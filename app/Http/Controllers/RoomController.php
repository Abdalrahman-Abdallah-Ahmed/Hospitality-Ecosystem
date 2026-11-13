<?php

namespace App\Http\Controllers;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Http\Controllers\Concerns\HandlesFrontDeskActions;
use App\Http\Requests\AssignRoomsRequest;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UnassignedArrivalsRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\RoomTypeResource;
use App\Http\Resources\UnassignedArrivalResource;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Reservations\RoomAssignmentService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    use HandlesFrontDeskActions;

    private const RESERVATION_RELATIONS = ['hotel', 'guest', 'reservationGuests.guest', 'reservationRooms.roomType', 'reservationRooms.room.roomType', 'reservationRooms.stay'];

    public function __construct(
        private readonly RoomAssignmentService $assignments,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Room::class);

        $query = Room::query()->with('roomType');

        $rooms = GenericQuery::apply($query, $request);

        // One read of each hotel for the whole page, for readiness.
        $hotels = Hotel::whereIn('id', collect($rooms->items())->pluck('hotel_id')->unique())->get()->keyBy('id');
        collect($rooms->items())->each(fn (Room $room) => $room->readinessHotel = $hotels->get($room->hotel_id));
        // The hotel scope limits this to the hotel the request works in.
        $roomTypes = RoomType::query()->orderBy('name')->get();

        $data = RoomResource::collection($rooms)->additional([
            'room_types' => RoomTypeResource::collection($roomTypes),
        ]);

        return apiResponse('Rooms fetched successfully.', 200, $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoomRequest $request)
    {
        $this->authorize('create', Room::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = resolveHotel($request->user());
        if (! $hotel) {
            return apiResponse('You must belong to a hotel.', 403);
        }

        if ($this->roomTypeIsDeleted($validated)) {
            return apiResponse('The selected room type has been deleted.', 422);
        }

        $invalidRelation = invalidRelation($hotel, ['roomTypes' => $validated['room_type_id'] ?? null]);
        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        // A new room is available and clean; status and housekeeping status
        // have their own actions (research R2).
        $room = Room::create([
            ...$validated,
            'hotel_id' => $hotel->id,
            'status' => RoomStatusesEnum::AVAILABLE,
            'housekeeping_status' => HousekeepingStatusesEnum::CLEAN,
        ]);

        return apiResponse('Room created successfully.', 201, RoomResource::make($room->load('roomType')->withReadiness()));
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room)
    {
        $this->authorize('view', $room);

        return apiResponse('Room fetched successfully.', 200, RoomResource::make($room->load('roomType')->withReadiness()));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoomRequest $request, Room $room)
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

        return apiResponse('Room updated successfully.', 200, RoomResource::make($room->load('roomType')->withReadiness()));
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

    /**
     * Puts physical rooms on a reservation's lines, changes or removes them,
     * ahead of arrival (spec 020). Moving a guest who is in the house is a
     * stay action (StayController::moveRoom).
     */
    public function assign(AssignRoomsRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('assignRoom', $reservation);

        $result = $this->assignments->assign(
            $reservation,
            array_map(fn (array $item) => [
                'line_id' => strtolower($item['line_id']),
                'room_id' => isset($item['room_id']) ? strtolower($item['room_id']) : null,
                'upgrade' => (bool) ($item['upgrade'] ?? false),
            ], $request->validated('assignments')),
            $this->overbookOverride($request),
        );

        return apiResponse('Rooms updated.', 200, [
            'reservation' => ReservationResource::make($result['reservation']->load(self::RESERVATION_RELATIONS)),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * Lines with no room yet whose guest has not arrived, on reservations
     * arriving in the window (FR-018), plus late arrivals that can still
     * come (arrival passed, departure not), marked `is_late`. A multi-room
     * reservation is checked in once its first room is, so its rooms still
     * on the way are listed too.
     */
    public function unassignedArrivals(UnassignedArrivalsRequest $request): JsonResponse
    {
        $this->authorize('assignRoom', Reservation::class);

        $hotel = resolveHotel($request->user());
        if (! $hotel) {
            return apiResponse('You must belong to a hotel.', 403);
        }

        $lines = $this->assignments->unassignedArrivals(
            $hotel,
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('sort') ?? 'arrival_date',
            $request->integer('per_page', 15),
        );

        return apiResponse('Unassigned arrivals fetched successfully.', 200, UnassignedArrivalResource::collection($lines));
    }
}
