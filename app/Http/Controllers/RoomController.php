<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Room;
use App\Support\RequestRules\GenericQuery;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Room::class);

        $rooms = GenericQuery::apply(
            Room::where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Rooms fetched successfully.', 200, $rooms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', Room::class);

        $validated = $request->validated();

        if ($validated['hotel_id'] !== $request->user()->hotel?->id) {
            return apiResponse('The selected hotel does not belong to you.', 403);
        }

        $room = Room::create($validated);

        return apiResponse('Room created successfully.', 201, $room);
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room)
    {
        $this->authorize('view', $room);

        return apiResponse('Room fetched successfully.', 200, $room);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Room $room)
    {
        $this->authorize('update', $room);

        $room->update($request->validated());

        return apiResponse('Room updated successfully.', 200, $room);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Room $room)
    {
        $this->authorize('delete', $room);

        $room->delete();

        return apiResponse('Room deleted successfully.', 200);
    }
}
