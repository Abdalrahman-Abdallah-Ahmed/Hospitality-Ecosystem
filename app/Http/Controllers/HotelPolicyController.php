<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\HotelPolicy;
use App\Support\RequestRules\GenericQuery;

class HotelPolicyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', HotelPolicy::class);

        $query = HotelPolicy::query();

        $policies = GenericQuery::apply($query, $request);

        return apiResponse('Hotel policies fetched successfully.', 200, $policies);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', HotelPolicy::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('User does not have an associated hotel.', 403);
        }

        $hotelPolicy = HotelPolicy::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Hotel policy created successfully.', 201, $hotelPolicy);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, HotelPolicy $hotelPolicy)
    {
        $this->authorize('update', $hotelPolicy);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotelPolicy->update($validated);

        return apiResponse('Hotel policy updated successfully.', 200, $hotelPolicy);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(HotelPolicy $hotelPolicy)
    {
        $this->authorize('delete', $hotelPolicy);
        $hotelPolicy->delete();

        return apiResponse('Hotel policy deleted successfully.', 200);
    }
}
