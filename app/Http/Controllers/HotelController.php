<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Support\Proactive\ProactiveSettings;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class HotelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Hotel::class);
        $hotels = GenericQuery::apply(
            Hotel::query(),
            $request
        );

        return apiResponse('Hotels fetched successfully.', 200, HotelResource::collection($hotels));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', Hotel::class);
        // A hotel that doesn't exist yet owns no teams; its defaults are
        // created with it (HotelOperationalDefaults).
        $validated = unsetAttributes($request->validated(), [
            'housekeeping_team_id', 'cleaning_task_category_id', 'inspection_task_category_id',
            'maintenance_team_id', 'maintenance_task_category_id',
        ]);

        $trashed = Hotel::onlyTrashed()->where('slug', $validated['slug'])->first();

        if ($trashed) {
            if ($trashed->owner_id !== ($validated['owner_id'] ?? null)) {
                return apiResponse('The slug has already been taken.', 422);
            }

            $trashed->restore();
            $trashed->update($validated);

            return apiResponse('Hotel created successfully.', 201, HotelResource::make($trashed));
        }

        $hotel = Hotel::create($validated);

        return apiResponse('Hotel created successfully.', 201, HotelResource::make($hotel));
    }

    /**
     * Display the specified resource.
     */
    public function show(Hotel $hotel)
    {
        $this->authorize('view', $hotel);

        return apiResponse('Hotel fetched successfully.', 200, HotelResource::make($hotel));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Hotel $hotel)
    {
        $this->authorize('update', $hotel);

        $validated = $this->withoutStrandedCategories($hotel, $request->validated());

        if ($error = $this->invalidHousekeepingDefaults($hotel, $validated)) {
            return $error;
        }

        if (array_key_exists('proactive_settings', $validated)) {
            $settings = $this->mergedProactiveSettings($hotel, (array) $validated['proactive_settings']);

            if (is_string($settings)) {
                return apiResponse($settings, 422);
            }

            $validated['proactive_settings'] = $settings;
        }

        // Turning inspection on starts the clock: rooms cleaned before then
        // still count as ready (FR-006).
        if (($validated['inspection_required'] ?? false) && ! $hotel->inspection_required) {
            $hotel->forceFill(['inspection_required_since' => now()]);
        }

        $hotel->update($validated);

        return apiResponse('Hotel updated successfully.', 200, HotelResource::make($hotel));
    }

    /**
     * Proactive messaging settings sent in part are laid over what the hotel
     * already has (SPEC-073). Returns the full settings to store, or the
     * first validation error.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|string
     */
    private function mergedProactiveSettings(Hotel $hotel, array $changes): array|string
    {
        if ($unknown = ProactiveSettings::unknownKeys($changes)) {
            return 'Unknown proactive setting ['.implode(', ', $unknown).'].';
        }

        $merged = ProactiveSettings::merge($hotel->proactiveSettings()->toArray(), $changes);
        $validator = Validator::make($merged, ProactiveSettings::rules());

        return $validator->fails() ? $validator->errors()->first() : $merged;
    }

    /**
     * Choosing another housekeeping or maintenance team without also choosing
     * its inspection or maintenance category clears the one left behind in
     * the old team, rather than refusing the change. The cleaning category
     * keeps its stricter rule (it must be chosen with the team, FR-013), and
     * a category sent with the request is always checked.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withoutStrandedCategories(Hotel $hotel, array $validated): array
    {
        $dependents = [
            'housekeeping_team_id' => ['inspection_task_category_id'],
            'maintenance_team_id' => ['maintenance_task_category_id'],
        ];

        foreach ($dependents as $teamField => $categoryFields) {
            if (! array_key_exists($teamField, $validated) || $validated[$teamField] === $hotel->{$teamField}) {
                continue;
            }

            foreach ($categoryFields as $categoryField) {
                $current = $hotel->{$categoryField};

                if (! array_key_exists($categoryField, $validated) && $current
                    && TaskCategory::withoutGlobalScope('hotel')->whereKey($current)->value('team_id') !== $validated[$teamField]) {
                    $validated[$categoryField] = null;
                }
            }
        }

        return $validated;
    }

    /**
     * The operational defaults must be this hotel's own teams and categories
     * (R8): each team active, the cleaning and inspection categories in the
     * housekeeping team, the maintenance category in the maintenance team.
     * Checked against the values the hotel will have after the update.
     */
    private function invalidHousekeepingDefaults(Hotel $hotel, array $validated): ?JsonResponse
    {
        $fields = [
            'housekeeping_team_id', 'cleaning_task_category_id', 'inspection_task_category_id',
            'maintenance_team_id', 'maintenance_task_category_id',
        ];

        if (array_intersect($fields, array_keys($validated)) === []) {
            return null;
        }

        $after = fn (string $field) => array_key_exists($field, $validated) ? $validated[$field] : $hotel->{$field};

        foreach (['housekeeping_team_id', 'maintenance_team_id'] as $field) {
            if ($invalid = invalidRelation($hotel, ['teams' => $after($field)])) {
                return apiResponse("The selected {$invalid} does not belong to you.", 403);
            }
        }

        foreach (['cleaning_task_category_id', 'inspection_task_category_id', 'maintenance_task_category_id'] as $field) {
            if ($invalid = invalidRelation($hotel, ['taskCategories' => $after($field)])) {
                return apiResponse("The selected {$invalid} does not belong to you.", 403);
            }
        }

        $rules = [
            'housekeeping' => ['housekeeping_team_id', ['cleaning_task_category_id' => 'cleaning', 'inspection_task_category_id' => 'inspection']],
            'maintenance' => ['maintenance_team_id', ['maintenance_task_category_id' => 'maintenance']],
        ];

        foreach ($rules as $teamName => [$teamField, $categories]) {
            $teamId = $after($teamField);
            $team = $teamId ? Team::withoutGlobalScope('hotel')->find($teamId) : null;

            if ($team && ! $team->is_active) {
                return apiResponse("The {$teamName} team must be active.", 422);
            }

            foreach ($categories as $categoryField => $label) {
                $categoryId = $after($categoryField);

                if ($categoryId && $teamId && TaskCategory::withoutGlobalScope('hotel')->whereKey($categoryId)->value('team_id') !== $teamId) {
                    return apiResponse("The {$label} task category must belong to the {$teamName} team.", 422);
                }
            }
        }

        return null;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Hotel $hotel)
    {
        $this->authorize('delete', $hotel);

        $hotel->delete();

        return apiResponse('Hotel deleted successfully.', 200);
    }
}
