<?php

namespace App\Services;

use App\Enums\CreatedBy;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\Priority;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Exceptions\CheckInOutException;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Support\Housekeeping\HousekeepingDefaults;
use App\Support\Reservations\ReservationCreator;
use App\Support\Reservations\RoomAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks guests in and out, one room (stay) or a whole reservation at a time
 * (SPEC-024, SPEC-025). The only place a stay becomes in-house or departed
 * outside the reservation import, used by the front-desk endpoints, the
 * Admin AI tools and the deprecated status change on the reservation edit.
 *
 * Every action is one transaction that locks in a fixed order — the
 * reservation, then its lines and stays, then the rooms involved — and checks
 * the rules only after locking, so a repeat or a second desk doing the same
 * thing waits, then finds it done and changes nothing.
 */
class StayLifecycleService
{
    public function __construct(
        private readonly StayService $stays,
    ) {}

    /**
     * Checks one room in. `$roomId` names a room for a line that has none
     * (FR-007a); `$at` is an earlier actual time today (FR-010a).
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<array{stay_id: string, message: string}>}
     */
    public function checkIn(Stay $stay, ?string $roomId = null, ?string $at = null): array
    {
        return $this->checkInStays($this->reservationOf($stay), [$stay->id], $roomId ? [$stay->id => $roomId] : [], $at);
    }

    /**
     * Checks in every expected room of the reservation, or none (FR-009).
     * Rooms already in the house are left as they are.
     *
     * @param  array<string, string>  $roomsByStayId  a room to assign per unassigned stay
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<array{stay_id: string, message: string}>}
     */
    public function checkInReservation(Reservation $reservation, array $roomsByStayId = [], ?string $at = null): array
    {
        return $this->checkInStays($reservation, null, $roomsByStayId, $at);
    }

    /**
     * Checks one room out.
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, cleaning_tasks: Collection<int, Task>}
     */
    public function checkOut(Stay $stay, ?string $at = null): array
    {
        return $this->checkOutStays($this->reservationOf($stay), [$stay->id], $at);
    }

    /**
     * Checks out every in-house room of the reservation.
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, cleaning_tasks: Collection<int, Task>}
     */
    public function checkOutReservation(Reservation $reservation, ?string $at = null): array
    {
        return $this->checkOutStays($reservation, null, $at);
    }

    /**
     * @param  list<string>|null  $stayIds  null: every expected stay of the reservation
     * @param  array<string, string>  $roomsByStayId
     */
    private function checkInStays(Reservation $reservation, ?array $stayIds, array $roomsByStayId, ?string $at): array
    {
        $hotel = Hotel::findOrFail($reservation->hotel_id);

        return DB::transaction(function () use ($reservation, $stayIds, $roomsByStayId, $at, $hotel) {
            [$reservation, $stays] = $this->lock($reservation);
            $reasons = [];

            foreach (array_keys($roomsByStayId) as $stayId) {
                $stay = $stays->get($stayId);

                if (! $stay || $stay->status !== StayStatus::EXPECTED) {
                    $reasons['rooms'][] = 'A room can only be named for a room of this reservation that is waiting to check in.';
                }
            }

            $targets = $stayIds === null
                ? $stays->filter(fn (Stay $stay) => $stay->status === StayStatus::EXPECTED && $this->isLive($stay))
                : $stays->only($stayIds);

            // Already in the house: nothing to do, and nothing to report (FR-010).
            $toCheckIn = $targets->reject(fn (Stay $stay) => $stay->status === StayStatus::IN_HOUSE);

            if ($toCheckIn->isEmpty() && $stayIds === null && ! $stays->contains(fn (Stay $stay) => $stay->status === StayStatus::IN_HOUSE)) {
                throw CheckInOutException::for(['stays' => ["Reservation is {$reservation->status->value}; no room is waiting to check in."]]);
            }

            if ($toCheckIn->isEmpty() && $reasons === []) {
                return $this->checkInResult($reservation, $stayIds === null ? $this->liveStays($reservation) : $targets->values(), []);
            }

            $time = $this->resolveTime($at, $hotel, null, 'checked_in_at', $reasons);
            $rooms = $this->lockRooms($toCheckIn->map(fn (Stay $stay) => $roomsByStayId[$stay->id] ?? $stay->reservationRoom?->room_id ?? $stay->room_id)->filter()->all());

            foreach ($toCheckIn as $stay) {
                foreach ($this->checkInReasons($reservation, $stay, $roomsByStayId[$stay->id] ?? null, $rooms, $hotel) as $key => $message) {
                    $reasons["stays.{$stay->id}.{$key}"][] = $message;
                }
            }

            if ($reasons !== []) {
                $failing = collect(array_keys($reasons))->filter(fn ($key) => str_starts_with($key, 'stays.'))
                    ->map(fn ($key) => explode('.', $key)[1])->unique()->count();

                throw CheckInOutException::for($reasons, $failing > 1 ? "{$failing} rooms cannot be checked in." : $this->firstMessage($reasons));
            }

            $warnings = [];

            foreach ($toCheckIn as $stay) {
                $room = $rooms->get($roomsByStayId[$stay->id] ?? $stay->reservationRoom?->room_id ?? $stay->room_id);

                if (isset($roomsByStayId[$stay->id])) {
                    $stay->reservationRoom->update(['room_id' => $room->id]);
                }

                if ($stay->room_id !== $room->id) {
                    $stay->update(['room_id' => $room->id]);
                }

                $this->withEnteredAt($stay, $at, fn () => $this->stays->checkIn($stay, $time));
                ReservationCreator::syncRoomOccupancy([$room->id]);

                if ($room->housekeeping_status !== HousekeepingStatusesEnum::CLEAN) {
                    $warnings[] = ['stay_id' => $stay->id, 'message' => "Room {$room->room_number} is {$room->housekeeping_status->value}."];
                }
            }

            if ($reservation->status !== ReservationStatus::CHECKED_IN) {
                $reservation->update(['status' => ReservationStatus::CHECKED_IN]);
            }

            return $this->checkInResult($reservation, $stayIds === null ? $this->liveStays($reservation) : $targets->values(), $warnings);
        });
    }

    /**
     * Why this stay cannot be checked in, keyed by what is wrong (R7). The
     * room is the one named for it, else the one on its line.
     *
     * @param  Collection<string, Room>  $rooms
     * @return array<string, string>
     */
    private function checkInReasons(Reservation $reservation, Stay $stay, ?string $namedRoomId, Collection $rooms, Hotel $hotel): array
    {
        $reasons = [];
        $line = $stay->reservationRoom;

        if (! in_array($reservation->status, [ReservationStatus::CONFIRMED, ReservationStatus::CHECKED_IN], true)) {
            $reasons['reservation_status'] = "Reservation is {$reservation->status->value}; only confirmed reservations can be checked in.";
        }

        if (! $this->isLive($stay) || $stay->status !== StayStatus::EXPECTED) {
            $reasons['stay_status'] = 'This room is '.($this->isLive($stay) ? $stay->status->value : 'cancelled').'.';

            return $reasons;
        }

        $today = $this->today($hotel);

        if ($today < $reservation->arrival_date->toDateString()) {
            $reasons['dates'] = "Arrival is {$reservation->arrival_date->toDateString()}.";
        } elseif ($today >= $reservation->departure_date->toDateString()) {
            $reasons['dates'] = 'The departure date has passed; correct the reservation dates first.';
        }

        $assignedRoomId = $line?->room_id;

        if ($namedRoomId !== null) {
            if (! $line) {
                $reasons['room_id'] = 'A room can only be named for a reservation line.';

                return $reasons;
            }

            if ($assignedRoomId !== null && $assignedRoomId !== $namedRoomId) {
                $reasons['room_id'] = "This line already has room {$line->room?->room_number}; reassign it first.";

                return $reasons;
            }

            if ($assignedRoomId === null && ($reason = RoomAssignmentRules::reason($line, $rooms->get($namedRoomId)))) {
                $reasons['room_id'] = $reason;

                return $reasons;
            }
        }

        $room = $rooms->get($namedRoomId ?? $assignedRoomId ?? $stay->room_id);

        if (! $room) {
            $reasons['room'] = 'Assign or name a room first.';

            return $reasons;
        }

        if ($room->isOutOfOrder()) {
            $reasons['room'] = "Room {$room->room_number} is out of order.";
        } elseif ($this->someoneElseIn($room, $stay)) {
            $reasons['room'] = "Room {$room->room_number} is occupied.";
        }

        return $reasons;
    }

    /**
     * @param  list<string>|null  $stayIds  null: every in-house stay of the reservation
     */
    private function checkOutStays(Reservation $reservation, ?array $stayIds, ?string $at): array
    {
        $hotel = Hotel::findOrFail($reservation->hotel_id);

        return DB::transaction(function () use ($reservation, $stayIds, $at, $hotel) {
            [$reservation, $stays] = $this->lock($reservation);
            $reasons = [];

            $targets = $stayIds === null
                ? $stays->filter(fn (Stay $stay) => $stay->status === StayStatus::IN_HOUSE)
                : $stays->only($stayIds);

            // Already gone: nothing to do, no second cleaning task (FR-014).
            $toCheckOut = $targets->reject(fn (Stay $stay) => $stay->status === StayStatus::DEPARTED);

            if ($toCheckOut->isEmpty()) {
                if ($stayIds === null && ! $stays->contains(fn (Stay $stay) => $stay->status === StayStatus::DEPARTED)) {
                    throw CheckInOutException::for(['stays' => ['No room of this reservation is checked in.']]);
                }

                return $this->checkOutResult($reservation, $targets->values(), collect());
            }

            foreach ($toCheckOut as $stay) {
                if ($stay->status !== StayStatus::IN_HOUSE) {
                    $reasons["stays.{$stay->id}.stay_status"][] = 'This room is '.$stay->status->value.'.';
                }
            }

            // FR-012a: the last room out may not leave rooms behind that never arrived.
            $leaving = $toCheckOut->modelKeys();
            $stillIn = $stays->filter(fn (Stay $stay) => $stay->status === StayStatus::IN_HOUSE && ! in_array($stay->id, $leaving, true));
            $waiting = $stays->filter(fn (Stay $stay) => $stay->status === StayStatus::EXPECTED && $this->isLive($stay));

            if ($stillIn->isEmpty() && $waiting->isNotEmpty()) {
                $list = $waiting->map(fn (Stay $stay) => trim($reservation->reservation_id.' '.($stay->reservationRoom?->roomType?->name ?? '').' ('.($stay->reservationRoom?->room?->room_number ?? 'unassigned').')'))->implode(', ');
                $reasons['stays.expected'][] = "Cancel the rooms that did not arrive first: {$list}.";
            }

            $times = [];

            foreach ($toCheckOut as $stay) {
                $times[$stay->id] = $this->resolveTime($at, $hotel, $stay->checked_in_at ? CarbonImmutable::parse($stay->checked_in_at) : null, 'checked_out_at', $reasons);
            }

            if ($reasons !== []) {
                throw CheckInOutException::for($reasons, $this->firstMessage($reasons));
            }

            $rooms = $this->lockRooms($toCheckOut->pluck('room_id')->filter()->all());
            $defaults = HousekeepingDefaults::for($hotel);
            $tasks = collect();

            foreach ($toCheckOut as $stay) {
                $this->withEnteredAt($stay, $at, fn () => $this->stays->checkOut($stay, $times[$stay->id], $hotel->timezone));

                $room = $rooms->get($stay->room_id);

                if (! $room) {
                    continue;
                }

                ReservationCreator::syncRoomOccupancy([$room->id]);
                $room->refresh();

                if ($room->housekeeping_status !== HousekeepingStatusesEnum::BLOCKED) {
                    $room->update(['housekeeping_status' => HousekeepingStatusesEnum::DIRTY]);
                }

                $tasks->push($this->cleaningTask($stay, $room, $defaults));
            }

            if (! $stays->contains(fn (Stay $stay) => $stay->fresh()->status === StayStatus::IN_HOUSE)) {
                $reservation->update(['status' => ReservationStatus::CHECKED_OUT]);
            }

            return $this->checkOutResult($reservation, $stayIds === null ? $toCheckOut->values() : $targets->values(), $tasks);
        });
    }

    /**
     * The turnover task for housekeeping (research R11). No notification yet:
     * SPEC-030 adds housekeeping notifications with de-duplication.
     *
     * @param  array{team: ?Team, category: ?TaskCategory}  $defaults
     */
    private function cleaningTask(Stay $stay, Room $room, array $defaults): Task
    {
        return Task::create([
            'hotel_id' => $stay->hotel_id,
            'room_id' => $room->id,
            'reservation_id' => $stay->reservation_id,
            'stay_id' => $stay->id,
            'guest_id' => $stay->guest_id,
            'assigned_to_team_id' => $defaults['team']?->id,
            'task_category_id' => $defaults['category']?->id,
            'title' => "Clean room {$room->room_number} after check-out",
            'created_by' => CreatedBy::SYSTEM,
            'status' => TaskStatus::PENDING,
            'priority' => Priority::NORMAL,
        ]);
    }

    /**
     * Locks the reservation, then its lines and stays, and returns fresh
     * copies: every check below reads what is committed now, not what the
     * caller loaded before waiting.
     *
     * @return array{Reservation, Collection<string, Stay>}
     */
    private function lock(Reservation $reservation): array
    {
        $locked = Reservation::withoutGlobalScope('hotel')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

        ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $stays = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->load(['reservationRoom.room', 'reservationRoom.roomType'])
            ->sortBy(fn (Stay $stay) => $stay->reservationRoom?->created_at?->format('Y-m-d H:i:s.u').'|'.$stay->reservationRoom?->id)
            ->keyBy('id');

        return [$locked, $stays];
    }

    /**
     * @param  array<int, string>  $roomIds
     * @return Collection<string, Room>
     */
    private function lockRooms(array $roomIds): Collection
    {
        return Room::withoutGlobalScope('hotel')
            ->whereIn('id', array_values(array_unique($roomIds)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The actual time of the action: now, or an earlier time today in the
     * hotel's timezone (FR-010a). A bad time adds a reason under `$field`.
     *
     * @param  array<string, list<string>>  $reasons
     */
    private function resolveTime(?string $given, Hotel $hotel, ?CarbonImmutable $notBefore, string $field, array &$reasons): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($given === null || $given === '') {
            return $now;
        }

        try {
            $time = CarbonImmutable::parse($given, $hotel->timezone);
        } catch (Throwable) {
            $reasons[$field] = ['The time is not a valid date and time.'];

            return $now;
        }

        if ($time->setTimezone($hotel->timezone)->toDateString() !== $this->today($hotel) || $time->greaterThan($now)) {
            $reasons[$field] = ['The time must be today (hotel time) and not in the future.'];
        } elseif ($notBefore && $time->lessThan($notBefore)) {
            $reasons[$field] = ['The check-out time cannot be before the check-in time.'];
        }

        return $time;
    }

    /**
     * Runs the stay's status change with `entered_at` on its audit row when
     * the desk gave an earlier actual time, so one row shows both (R17).
     */
    private function withEnteredAt(Stay $stay, ?string $given, callable $change): void
    {
        $stay->auditExtras = $given ? ['entered_at' => CarbonImmutable::now()] : [];

        try {
            $change();
        } finally {
            $stay->auditExtras = [];
        }
    }

    private function someoneElseIn(Room $room, Stay $stay): bool
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('room_id', $room->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->where('id', '!=', $stay->id)
            ->exists();
    }

    private function isLive(Stay $stay): bool
    {
        return $stay->status !== StayStatus::CANCELLED
            && $stay->reservationRoom?->status !== ReservationRoomStatus::CANCELLED;
    }

    private function reservationOf(Stay $stay): Reservation
    {
        $reservation = $stay->reservation_id
            ? Reservation::withoutGlobalScope('hotel')->find($stay->reservation_id)
            : null;

        if (! $reservation) {
            throw CheckInOutException::for(["stays.{$stay->id}.reservation" => ['This stay has no reservation to check in or out.']]);
        }

        return $reservation;
    }

    private function today(Hotel $hotel): string
    {
        return CarbonImmutable::now($hotel->timezone)->toDateString();
    }

    /**
     * @return Collection<int, Stay>
     */
    private function liveStays(Reservation $reservation): Collection
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', StayStatus::CANCELLED->value)
            ->get();
    }

    /**
     * @param  array<string, list<string>>  $reasons
     */
    private function firstMessage(array $reasons): string
    {
        return collect($reasons)->flatten()->first();
    }

    private function checkInResult(Reservation $reservation, Collection $stays, array $warnings): array
    {
        return [
            'reservation' => $reservation->fresh(),
            'stays' => $this->freshStays($stays),
            'warnings' => $warnings,
        ];
    }

    private function checkOutResult(Reservation $reservation, Collection $stays, Collection $tasks): array
    {
        return [
            'reservation' => $reservation->fresh(),
            'stays' => $this->freshStays($stays),
            'cleaning_tasks' => $tasks,
        ];
    }

    /**
     * @return Collection<int, Stay>
     */
    private function freshStays(Collection $stays): Collection
    {
        return Stay::withoutGlobalScope('hotel')
            ->whereIn('id', $stays->pluck('id'))
            ->with(['guest', 'reservation', 'reservationRoom.roomType', 'room'])
            ->get()
            ->values();
    }
}
