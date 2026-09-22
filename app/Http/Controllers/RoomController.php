<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\RoomResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\RequestRules\GenericQuery;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Room::class);

        $query = Room::query()->with('roomType');

        $rooms = GenericQuery::apply($query, $request);
        $roomTypes = RoomType::where('hotel_id', $request->user()->hotel_id)->get();

        $data = RoomResource::collection($rooms)->additional([
            'room_types' => RoomTypeResource::collection($roomTypes),
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

        if ($this->roomTypeIsDeleted($validated)) {
            return apiResponse('The selected room type has been deleted.', 422);
        }

        $invalidRelation = invalidRelation($hotel, ['roomTypes' => $validated['room_type_id'] ?? null]);
        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $room = Room::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Room created successfully.', 201, RoomResource::make($room->load('roomType')));
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room)
    {
        $this->authorize('view', $room);

        return apiResponse('Room fetched successfully.', 200, RoomResource::make($room->load('roomType')));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Room $room)
    {
        $this->authorize('update', $room);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        if ($this->roomTypeIsDeleted($validated)) {
            return apiResponse('The selected room type has been deleted.', 422);
        }

        $invalidRelation = invalidRelation($room->hotel, ['roomTypes' => $validated['room_type_id'] ?? null]);
        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $room->update($validated);

        return apiResponse('Room updated successfully.', 200, RoomResource::make($room->load('roomType')));
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

    /**
     * The `exists` rule accepts soft-deleted room types, and invalidRelation()
     * would then report one as another hotel's. The hotel scope keeps this to
     * the caller's own hotels, so a foreign id still falls through to the 403.
     */
    private function roomTypeIsDeleted(array $validated): bool
    {
        $roomTypeId = $validated['room_type_id'] ?? null;

        return $roomTypeId !== null && RoomType::onlyTrashed()->whereKey($roomTypeId)->exists();
    }
}
