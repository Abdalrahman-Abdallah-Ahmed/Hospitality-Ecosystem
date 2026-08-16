<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\ActivityCategory;
use App\Support\RequestRules\GenericQuery;

class ActivityCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', ActivityCategory::class);

        $activityCategories = GenericQuery::apply(
            ActivityCategory::where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Activity categories fetched successfully.', 200, $activityCategories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', ActivityCategory::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $activityCategory = ActivityCategory::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Activity category created successfully.', 201, $activityCategory);
    }

    /**
     * Display the specified resource.
     */
    public function show(ActivityCategory $activityCategory)
    {
        $this->authorize('view', $activityCategory);

        return apiResponse('Activity category fetched successfully.', 200, $activityCategory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, ActivityCategory $activityCategory)
    {
        $this->authorize('update', $activityCategory);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $activityCategory->update([...$validated, 'hotel_id' => $activityCategory->hotel_id]);

        return apiResponse('Activity category updated successfully.', 200, $activityCategory);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ActivityCategory $activityCategory)
    {
        $this->authorize('delete', $activityCategory);

        $activityCategory->delete();

        return apiResponse('Activity category deleted successfully.', 200);
    }
}
