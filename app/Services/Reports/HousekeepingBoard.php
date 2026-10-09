<?php

namespace App\Services\Reports;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The housekeeping board (SPEC-030, FR-030): every room with its
 * housekeeping and room status, the in-house guest's departure and its open
 * cleaning or inspection task. Three queries whatever the size of the hotel.
 * Shared by the board endpoint and the Admin AI (SPEC-055 R4).
 */
class HousekeepingBoard
{
    /**
     * @param  array{housekeeping_status?: ?string, status?: ?string, floor?: mixed, building?: ?string, team_id?: ?string}  $filters
     * @return array{date: string, inspection_required: bool, counts: Collection<string, int>, rows: Collection<int, array{room: Room, departure_date: ?string, open_task: ?Task}>}
     */
    public function for(Hotel $hotel, array $filters = []): array
    {
        $filled = fn (string $key) => isset($filters[$key]) && $filters[$key] !== '';

        // A filled check, not the value's truthiness: floor "0" (the ground
        // floor) is a real filter.
        $rooms = Room::query()
            ->where('hotel_id', $hotel->id)
            ->with('roomType')
            ->when($filled('housekeeping_status'), fn ($query) => $query->where('housekeeping_status', $filters['housekeeping_status']))
            ->when($filled('status'), fn ($query) => $query->where('status', $filters['status']))
            ->when($filled('floor'), fn ($query) => $query->where('floor', $filters['floor']))
            ->when($filled('building'), fn ($query) => $query->where('building', $filters['building']))
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

        if ($filled('team_id')) {
            $rooms = $rooms->filter(fn (Room $room) => $openTasks->get($room->id)?->contains('assigned_to_team_id', $filters['team_id']))->values();
        }

        $counts = collect(HousekeepingStatusesEnum::cases())
            ->mapWithKeys(fn (HousekeepingStatusesEnum $status) => [$status->value => $rooms->where('housekeeping_status', $status)->count()])
            ->put('out_of_order', $rooms->where('status', RoomStatusesEnum::OUT_OF_ORDER)->count());

        return [
            'date' => CarbonImmutable::now($hotel->timezone)->toDateString(),
            'inspection_required' => (bool) $hotel->inspection_required,
            'counts' => $counts,
            'rows' => $rooms->map(function (Room $room) use ($hotel, $departures, $openTasks) {
                $room->readinessHotel = $hotel;
                $departure = $departures->get($room->id);

                return [
                    'room' => $room,
                    'departure_date' => $departure ? CarbonImmutable::parse($departure)->toDateString() : null,
                    'open_task' => $openTasks->get($room->id)?->first(),
                ];
            })->values(),
        ];
    }
}
