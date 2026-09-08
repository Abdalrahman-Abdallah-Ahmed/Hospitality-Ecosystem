<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Support\RequestRules\GenericQuery;

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

        $hotel->update($request->validated());

        return apiResponse('Hotel updated successfully.', 200, HotelResource::make($hotel));
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
