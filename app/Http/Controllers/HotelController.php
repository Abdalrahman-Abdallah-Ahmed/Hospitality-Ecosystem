<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

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
        $validated = $request->validated();

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

        $validated = $request->validated();

        if ($error = $this->invalidHousekeepingDefaults($hotel, $validated)) {
            return $error;
        }

        $hotel->update($validated);

        return apiResponse('Hotel updated successfully.', 200, HotelResource::make($hotel));
    }

    /**
     * The cleaning-task defaults (FR-013) must be this hotel's own team and
     * category, the team must be active, and the category must belong to it.
     * Checked against the values the hotel will have after the update.
     */
    private function invalidHousekeepingDefaults(Hotel $hotel, array $validated): ?JsonResponse
    {
        if (! array_key_exists('housekeeping_team_id', $validated) && ! array_key_exists('cleaning_task_category_id', $validated)) {
            return null;
        }

        $teamId = array_key_exists('housekeeping_team_id', $validated) ? $validated['housekeeping_team_id'] : $hotel->housekeeping_team_id;
        $categoryId = array_key_exists('cleaning_task_category_id', $validated) ? $validated['cleaning_task_category_id'] : $hotel->cleaning_task_category_id;

        if ($invalid = invalidRelation($hotel, ['teams' => $teamId, 'taskCategories' => $categoryId])) {
            return apiResponse("The selected {$invalid} does not belong to you.", 403);
        }

        $team = $teamId ? Team::withoutGlobalScope('hotel')->find($teamId) : null;

        if ($team && ! $team->is_active) {
            return apiResponse('The housekeeping team must be active.', 422);
        }

        if ($categoryId && $teamId && TaskCategory::withoutGlobalScope('hotel')->whereKey($categoryId)->value('team_id') !== $teamId) {
            return apiResponse('The cleaning task category must belong to the housekeeping team.', 422);
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
