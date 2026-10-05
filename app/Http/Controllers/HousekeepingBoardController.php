<?php

namespace App\Http\Controllers;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Http\Requests\HousekeepingBoardRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The housekeeping board (SPEC-030, FR-030): every room with its
 * housekeeping and room status, readiness, the in-house guest's departure
 * and its open cleaning or inspection task. Three queries whatever the size
 * of the hotel.
 */
class HousekeepingBoardController extends Controller
{
    public function __invoke(HousekeepingBoardRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        // filled(), not the value's truthiness: floor "0" (the ground floor)
        // is a real filter.
        $rooms = Room::query()
            ->where('hotel_id', $hotel->id)
            ->with('roomType')
            ->when($request->filled('housekeeping_status'), fn ($query) => $query->where('housekeeping_status', $request->validated('housekeeping_status')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->validated('status')))
            ->when($request->filled('floor'), fn ($query) => $query->where('floor', $request->validated('floor')))
            ->when($request->filled('building'), fn ($query) => $query->where('building', $request->validated('building')))
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get();

        $departures = Stay::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->whereIn('room_id', $rooms->modelKeys())
            ->pluck('planned_departure_date', 'room_id');

        $openTasks = Task::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('room_id', $rooms->modelKeys())
            ->whereNotNull('housekeeping_kind')
            ->open()
            ->orderBy('created_at')
            ->get()
            ->groupBy('room_id');

        if ($teamId = $request->validated('team_id')) {
            $rooms = $rooms->filter(fn (Room $room) => $openTasks->get($room->id)?->contains('assigned_to_team_id', $teamId))->values();
        }

        $counts = collect(HousekeepingStatusesEnum::cases())
            ->mapWithKeys(fn (HousekeepingStatusesEnum $status) => [$status->value => $rooms->where('housekeeping_status', $status)->count()])
            ->put('out_of_order', $rooms->where('status', RoomStatusesEnum::OUT_OF_ORDER)->count());

        return apiResponse('Housekeeping board fetched successfully.', 200, [
            'date' => CarbonImmutable::now($hotel->timezone)->toDateString(),
            'inspection_required' => (bool) $hotel->inspection_required,
            'counts' => $counts,
            'rooms' => $rooms->map(function (Room $room) use ($hotel, $departures, $openTasks) {
                $room->readinessHotel = $hotel;
                $task = $openTasks->get($room->id)?->first();
                $departure = $departures->get($room->id);

                return [
                    'room' => RoomResource::make($room)->resolve(),
                    'departure_date' => $departure ? CarbonImmutable::parse($departure)->toDateString() : null,
                    'open_task' => $task ? [
                        'id' => $task->id,
                        'housekeeping_kind' => $task->housekeeping_kind,
                        'cleaning_reason' => $task->cleaning_reason,
                        'status' => $task->status,
                        'assigned_to_team_id' => $task->assigned_to_team_id,
                        'assigned_to_user_id' => $task->assigned_to_user_id,
                    ] : null,
                ];
            })->values(),
        ]);
    }
}
