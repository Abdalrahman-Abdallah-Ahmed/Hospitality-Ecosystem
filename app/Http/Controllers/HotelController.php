<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Hotel;
use App\Support\RequestRules\GenericQuery;

class HotelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $hotels = GenericQuery::apply(
            Hotel::where('owner_id', $request->user()->id),
            $request
        );

        return apiResponse('Hotels fetched successfully.', 200, $hotels);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $hotel = Hotel::create([
            ...$request->validated(),
            'owner_id' => $request->user()->id,
        ]);

        return apiResponse('Hotel created successfully.', 201, $hotel);
    }

    /**
     * Display the specified resource.
     */
    public function show(Hotel $hotel)
    {
        if ($hotel->owner_id !== request()->user()->id) {
            return apiResponse('You do not have access to this hotel.', 403);
        }

        return apiResponse('Hotel fetched successfully.', 200, $hotel);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Hotel $hotel)
    {
        if ($hotel->owner_id !== $request->user()->id) {
            return apiResponse('You do not have access to this hotel.', 403);
        }

        $hotel->update($request->validated());

        return apiResponse('Hotel updated successfully.', 200, $hotel);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Hotel $hotel)
    {
        if ($hotel->owner_id !== request()->user()->id) {
            return apiResponse('You do not have access to this hotel.', 403);
        }

        $hotel->delete();

        return apiResponse('Hotel deleted successfully.', 200);
    }
}
