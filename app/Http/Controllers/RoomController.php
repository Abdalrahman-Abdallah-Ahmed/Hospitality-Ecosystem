<?php

namespace App\Http\Controllers;

use App\Enums\RoomTypes;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\RoomResource;
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

        $query = Room::query();

        $rooms = GenericQuery::apply($query, $request);

        $data = RoomResource::collection($rooms)->additional([
            'room_types' => RoomTypes::cases(),
        ]);

        return apiResponse('Rooms fetched successfully.', 200, $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', Room::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $room = Room::create([...$validated, 'hotel_id' => $hotel->id]);

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

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $room->update($validated);

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
