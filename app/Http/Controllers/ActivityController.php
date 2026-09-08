<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Support\RequestRules\GenericQuery;

class ActivityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Activity::class);

        $query = Activity::with('category');

        $activities = GenericQuery::apply($query, $request);

        return apiResponse('Activities fetched successfully.', 200, ActivityResource::collection($activities));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', Activity::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'activityCategories' => $validated['category_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $activity = Activity::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Activity created successfully.', 201, ActivityResource::make($activity->load('category')));
    }

    /**
     * Display the specified resource.
     */
    public function show(Activity $activity)
    {
        $this->authorize('view', $activity);

        return apiResponse('Activity fetched successfully.', 200, ActivityResource::make($activity->load('category')));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $invalidRelation = invalidRelation($activity->hotel, [
            'activityCategories' => $validated['category_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $activity->update($validated);

        return apiResponse('Activity updated successfully.', 200, ActivityResource::make($activity->load('category')));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Activity $activity)
    {
        $this->authorize('delete', $activity);

        $activity->delete();

        return apiResponse('Activity deleted successfully.', 200);
    }
}
