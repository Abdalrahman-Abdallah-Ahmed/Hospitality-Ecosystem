<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Requests\HotelListRequest;
use App\Http\Resources\HotelResource;
use App\Http\Resources\HotelSummaryResource;
use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Support\Audit\EventLogger;
use App\Support\Proactive\ProactiveSettings;
use App\Support\RequestRules\GenericQuery;
use App\Support\Suspension\ImpersonatedRead;
use App\Support\Suspension\SuspendedResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HotelController extends Controller
{
    /**
     * The column that decides who a hotel belongs to. Never written here, for
     * any caller: moving a hotel between accounts has rules that protect both
     * customers, which live in App\Services\Superadmin\HotelAdministration.
     */
    private const PLATFORM_ATTRIBUTES = ['hotel_group_id'];

    /**
     * Display a listing of the resource.
     */
    public function index(HotelListRequest $request)
    {
        $this->authorize('viewAny', Hotel::class);

        // Hotel isn't scoped by BelongsToHotel, and EnsureNotSuspended has
        // already taken suspended hotels out of this list.
        $hotelIds = TenantContext::hotelIds();
        $query = Hotel::query()->when($hotelIds !== null, fn ($query) => $query->whereIn('hotels.id', $hotelIds));

        $hotels = GenericQuery::apply($query, $request);

        return apiResponse('Hotels fetched successfully.', 200, $request->showsFullRecords()
            ? HotelResource::collection($hotels)
            : HotelSummaryResource::collection($hotels));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Hotel $hotel)
    {
        $this->authorize('view', $hotel);

        if ($this->refusedAsSuspended($hotel) && ! ImpersonatedRead::allows($request)) {
            return SuspendedResponse::forHotel($hotel);
        }

        return apiResponse('Hotel fetched successfully.', 200, HotelResource::make($hotel));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Hotel $hotel)
    {
        $this->authorize('update', $hotel);

        if ($this->refusedAsSuspended($hotel)) {
            return SuspendedResponse::forHotel($hotel);
        }

        $validated = $this->withoutStrandedCategories(
            $hotel,
            unsetAttributes($request->validated(), self::PLATFORM_ATTRIBUTES),
        );

        // In this hotel's scope: its teams are hotel-scoped, and an admin can
        // edit a hotel other than the one the request works in.
        $error = TenantContext::runForHotel($hotel->getKey(), fn () => $this->invalidHousekeepingDefaults($hotel, $validated));

        if ($error) {
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

        $memoriesWere = (bool) $hotel->guest_memories_enabled;

        $hotel->update($validated);

        // The hotel is not an audited model, so turning guest memories on or
        // off is recorded here (SPEC-084 FR-032).
        if (array_key_exists('guest_memories_enabled', $validated) && $memoriesWere !== (bool) $hotel->guest_memories_enabled) {
            EventLogger::record($hotel, 'guest_memories_toggled', changes: [
                'guest_memories_enabled' => ['from' => $memoriesWere, 'to' => (bool) $hotel->guest_memories_enabled],
            ]);
        }

        return apiResponse('Hotel updated successfully.', 200, HotelResource::make($hotel));
    }

    /**
     * Asked only after authorization, so a caller who can't reach a hotel gets
     * the normal answer and never learns it is suspended. A super admin's null
     * scope is never refused.
     */
    private function refusedAsSuspended(Hotel $hotel): bool
    {
        return TenantContext::hotelIds() !== null && $hotel->isSuspended();
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
}
