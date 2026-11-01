<?php

namespace App\Services\Reservations;

use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Services\AvailabilityService;
use App\Support\Audit\EventLogger;
use App\Support\Reservations\ReservationLock;
use App\Support\Reservations\RoomAssignmentRules;
use App\Support\Reservations\RoomHoldConflict;
use App\Support\Stays\FrontDeskLists;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Putting physical rooms on reservation lines, changing and removing them,
 * and moving a guest in the house to another room (SPEC-021, spec 020).
 *
 * The staff endpoints and the Admin AI both come here. The checks give the
 * precise message (RoomAssignmentRules); the write goes through
 * ReservationCommands::update(), so stays, room occupancy, cleaning of a room
 * a guest left and the availability guard behave as on any edit; and the
 * `reservation_rooms_no_overlap` constraint backs it all in the database.
 *
 * Lock order, shared with check-in and no-shows: the reservation, its lines
 * and stays, then room types, then rooms.
 */
class RoomAssignmentService
{
    public function __construct(
        private readonly ReservationCommands $reservations,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * Applies every assignment together, or none. Each item is
     * `['line_id' => …, 'room_id' => ?string, 'upgrade' => ?bool]`; a null
     * room takes the room off the line. Lines not named keep their room.
     * Rules are checked on the final state, so two lines can swap rooms.
     *
     * @param  list<array{line_id: string, room_id: ?string, upgrade?: bool}>  $assignments
     * @return array{reservation: Reservation, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function assign(Reservation $reservation, array $assignments, bool $overbookOverride = false): array
    {
        return RoomHoldConflict::rethrow(fn () => DB::transaction(function () use ($reservation, $assignments, $overbookOverride) {
            [$reservation, $stays] = ReservationLock::lock($reservation);
            $lines = $reservation->reservationRooms()->get()->keyBy('id');
            $stayOf = $stays->keyBy('reservation_room_id');
            $nights = $this->nights($reservation);
            $rooms = $this->lockRooms($reservation, $lines, collect($assignments)->pluck('room_id'));

            $changes = [];
            $keys = [];
            $upgrades = [];
            $warnings = [];

            foreach (array_values($assignments) as $index => $item) {
                $key = "assignments.{$index}.room_id";
                $line = $lines->get($item['line_id']);

                if (! $line) {
                    throw ValidationException::withMessages(["assignments.{$index}.line_id" => 'This room line is not on the reservation.']);
                }

                $roomId = $item['room_id'] ?? null;

                if ($roomId === $line->room_id) {
                    continue;
                }

                $stay = $stayOf->get($line->id);
                RoomAssignmentRules::assertChangeable($line, $key, $reservation->status, $stay?->status);

                if ($stay?->status === StayStatus::IN_HOUSE) {
                    $current = $stay->room?->room_number ?? $line->room?->room_number;

                    throw ValidationException::withMessages([$key => "Room {$current} is checked in; move the guest with the room-move action."]);
                }

                if ($roomId !== null) {
                    $room = $rooms->get($roomId);
                    $upgrade = (bool) ($item['upgrade'] ?? false);

                    if ($reason = RoomAssignmentRules::reason($line, $room, $upgrade, $nights, ignoreOwnReservation: true)) {
                        throw ValidationException::withMessages([$key => $reason]);
                    }

                    if ($room->room_type_id !== $line->room_type_id) {
                        $upgrades[$line->id] = $room;
                    }

                    if ($room->isOutOfOrder()) {
                        $warnings[] = "Room {$room->room_number} is out of order; return it to service before check-in.";
                    }
                }

                $changes[$line->id] = $roomId;
                $keys[$line->id] = $key;
            }

            if ($changes !== []) {
                $this->write($reservation, $lines, $changes, $keys, 'assignments', $overbookOverride, upgradeLineIds: array_keys($upgrades));
                $this->recordUpgrades($lines, $upgrades);
            }

            return ['reservation' => $reservation->fresh(), 'warnings' => $warnings];
        }), 'assignments');
    }

    /**
     * Moves the guest of an in-house stay to `$room` for the rest of the
     * stay (FR-013, FR-014). The old room is left dirty with a cleaning task,
     * as on check-out; nothing is sent to the guest.
     *
     * @return array{stay: Stay, vacated_room: Room, cleaning_task: ?Task, open_room_change_requests: Collection<int, Task>, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function move(Stay $stay, Room $room, string $reason, bool $upgrade = false, bool $overbookOverride = false): array
    {
        return RoomHoldConflict::rethrow(fn () => DB::transaction(function () use ($stay, $room, $reason, $upgrade, $overbookOverride) {
            [$reservation, $stays] = ReservationLock::lock($stay->reservationOrFail());
            $stay = $stays->get($stay->id);
            $line = $stay?->reservationRoom;

            if (! $stay || $stay->status !== StayStatus::IN_HOUSE || ! $line) {
                throw ValidationException::withMessages(['stay' => 'Only a guest in the house can be moved to another room.']);
            }

            $lines = $reservation->reservationRooms()->get()->keyBy('id');
            $room = $this->lockRooms($reservation, $lines, collect([$room->id]))->get($room->id);
            $vacated = Room::withoutGlobalScope('hotel')->findOrFail($stay->room_id);

            if ($room?->id === $vacated->id) {
                throw ValidationException::withMessages(['room_id' => "The guest is already in room {$vacated->room_number}."]);
            }

            // Every night of the stay, not only those left (spec 020 FR-013):
            // a line has one room for all its nights (per-room date ranges are
            // post-MVP), so another live booking of the room on an earlier
            // night is a real conflict to sort out first. The audit event
            // keeps which room the guest slept in before the move.
            if ($why = RoomAssignmentRules::reason($line, $room, $upgrade, $this->nights($reservation))) {
                throw ValidationException::withMessages(['room_id' => $why]);
            }

            if ($room->isOutOfOrder()) {
                throw ValidationException::withMessages(['room_id' => "Room {$room->room_number} is out of order."]);
            }

            if (DB::query()->fromSub(ReservationRoom::inHouseRoomIds(), 'in_house')->where('room_id', $room->id)->exists()) {
                throw ValidationException::withMessages(['room_id' => "Room {$room->room_number} is occupied."]);
            }

            $this->write($reservation, $lines, [$line->id => $room->id], [$line->id => 'room_id'], 'room_id', $overbookOverride, allowInHouseMove: true, upgradeLineIds: $room->room_type_id !== $line->room_type_id ? [$line->id] : []);

            if ($room->room_type_id !== $line->room_type_id) {
                $this->recordUpgrades($lines, [$line->id => $room]);
            }

            $stay = $stay->fresh();
            EventLogger::record($stay, 'room_moved', changes: [
                'from_room' => $vacated->room_number,
                'to_room' => $room->room_number,
            ], reason: $reason);

            return [
                'stay' => $stay,
                'vacated_room' => $vacated->fresh(),
                'cleaning_task' => Task::withoutGlobalScope('hotel')
                    ->where('room_id', $vacated->id)
                    ->where('housekeeping_kind', HousekeepingKind::CLEANING)
                    ->open()
                    ->first(),
                'open_room_change_requests' => Task::withoutGlobalScope('hotel')
                    ->where('hotel_id', $stay->hotel_id)
                    ->where('stay_id', $stay->id)
                    ->where('guest_signal', GuestSignal::ROOM_CHANGE_REQUEST->value)
                    ->open()
                    ->get(),
                'warnings' => [],
            ];
        }), 'room_id');
    }

    /**
     * Lines with no room yet whose guest has not arrived, on reservations
     * arriving from `$from` (default the hotel's today) to `$to` (default a
     * week later) (FR-018), plus late arrivals that can still come (arrival
     * passed, departure not), marked `is_late`. A multi-room reservation is
     * checked in once its first room is, so its rooms still on the way are
     * listed too. `$sort` is arrival_date, reservation_code or room_type,
     * `-` first for descending.
     *
     * @return LengthAwarePaginator<int, ReservationRoom>
     */
    public function unassignedArrivals(Hotel $hotel, ?string $from, ?string $to, string $sort, int $perPage): LengthAwarePaginator
    {
        $today = FrontDeskLists::today($hotel);
        $from = $from ?? $today;
        $to ??= CarbonImmutable::parse($from)->addDays(7)->toDateString();
        $column = match (ltrim($sort, '-')) {
            'reservation_code' => 'reservations.reservation_id',
            'room_type' => 'room_types.name',
            default => 'reservations.arrival_date',
        };

        return ReservationRoom::query()
            ->select('reservation_rooms.*')
            ->selectRaw('reservations.arrival_date < ? as is_late', [$today])
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->join('room_types', 'room_types.id', '=', 'reservation_rooms.room_type_id')
            ->where('reservation_rooms.hotel_id', $hotel->id)
            ->whereNull('reservation_rooms.room_id')
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->whereNull('reservations.deleted_at')
            ->whereIn('reservations.status', array_map(fn (ReservationStatus $status) => $status->value, ReservationStatus::holdingInventory()))
            ->whereNotExists(fn ($stay) => $stay->from('stays')
                ->whereColumn('stays.reservation_room_id', 'reservation_rooms.id')
                ->whereNull('stays.deleted_at')
                ->where('stays.status', '!=', StayStatus::EXPECTED->value))
            ->where(fn ($query) => $query
                ->whereBetween('reservations.arrival_date', [$from, $to])
                ->orWhere(fn ($late) => $late->where('reservations.arrival_date', '<', $today)->where('reservations.departure_date', '>', $today)))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('reservation_rooms.id')
            ->with(['reservation.reservationGuests.guest', 'reservation.guest', 'roomType'])
            ->paginate($perPage);
    }

    /**
     * The hotel's rooms among `$roomIds`, locked, after the room types every
     * line and every room may count against (an upgrade moves a unit from
     * the line's type to the room's).
     *
     * @param  Collection<string, ReservationRoom>  $lines
     * @param  Collection<int, ?string>  $roomIds
     * @return Collection<string, Room>
     */
    private function lockRooms(Reservation $reservation, Collection $lines, Collection $roomIds): Collection
    {
        $roomIds = $roomIds->filter()->unique()->values();

        // Types before rooms (the shared lock order), so the types of the
        // target rooms and the lines' current rooms are read first, unlocked.
        $this->availability->lockTypes($reservation->hotel_id, [
            ...$lines->pluck('room_type_id')->all(),
            ...Room::withoutGlobalScope('hotel')
                ->where('hotel_id', $reservation->hotel_id)
                ->whereIn('id', [...$roomIds, ...$lines->pluck('room_id')->filter()])
                ->pluck('room_type_id')
                ->all(),
        ]);

        return Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $reservation->hotel_id)
            ->whereIn('id', $roomIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function nights(Reservation $reservation): array
    {
        return [$reservation->arrival_date->toDateString(), $reservation->departure_date->toDateString()];
    }

    /**
     * Writes the changes through the general edit, passing every live line
     * so none is cancelled. The edit names errors after its own `rooms` list;
     * they come back under the caller's keys: `$keys` per line, `$fallback`
     * for errors about no one line (a race lost to another booking).
     *
     * @param  Collection<string, ReservationRoom>  $lines
     * @param  array<string, ?string>  $changes
     * @param  array<string, string>  $keys
     * @param  list<string>  $upgradeLineIds
     *
     * @throws ValidationException
     */
    private function write(Reservation $reservation, Collection $lines, array $changes, array $keys, string $fallback, bool $overbookOverride, bool $allowInHouseMove = false, array $upgradeLineIds = []): void
    {
        $live = $lines->filter(fn (ReservationRoom $line) => $line->status !== ReservationRoomStatus::CANCELLED)->values();
        $rooms = $live->map(fn (ReservationRoom $line) => [
            'id' => $line->id,
            'room_id' => array_key_exists($line->id, $changes) ? $changes[$line->id] : $line->room_id,
        ])->all();

        try {
            $this->reservations->update($reservation, [], $rooms, overbookOverride: $overbookOverride, allowInHouseMove: $allowInHouseMove, upgradeLineIds: $upgradeLineIds);
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $lineId = preg_match('/^rooms\.(\d+)\./', $key, $match) ? $live->get((int) $match[1])?->id : null;
                $field = $lineId !== null ? ($keys[$lineId] ?? $fallback) : $fallback;
                $errors[$field] = [...($errors[$field] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * An upgrade is audited as one, so the log tells it from a mistake
     * (FR-025): the booked type and the type of the room given.
     *
     * @param  Collection<string, ReservationRoom>  $lines
     * @param  array<string, Room>  $upgrades
     */
    private function recordUpgrades(Collection $lines, array $upgrades): void
    {
        foreach ($upgrades as $lineId => $room) {
            $line = $lines[$lineId]->load('roomType');

            EventLogger::record($line, 'room_upgraded', changes: [
                'booked_type' => $line->roomType?->name,
                'room_type' => $room->roomType()->value('name'),
                'room' => $room->room_number,
            ]);
        }
    }
}
