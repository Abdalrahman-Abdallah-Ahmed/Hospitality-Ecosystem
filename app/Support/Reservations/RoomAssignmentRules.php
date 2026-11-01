<?php

namespace App\Support\Reservations;

use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether a physical room may be put on a reservation line: a room of the
 * line's hotel and room type (or another type, as an upgrade) that no other
 * live line holds on any of the line's nights (SPEC-021 FR-002). Every path
 * that puts a room on a line asks here first, for a clear message; the
 * `reservation_rooms_no_overlap` constraint backs it in the database, so a
 * race or a forgotten path still cannot double-book a room.
 *
 * Whether a line may change room at all (cancelled, closed, departed) is a
 * separate question, assertChangeable(): the no-show undo asks reason() while
 * the stay is still a no-show.
 */
class RoomAssignmentRules
{
    /**
     * The reason `$room` cannot go on `$line`, or null when it can. `$nights`
     * ([arrival, departure]) checks an edit's new dates before they are saved;
     * by default the reservation's saved dates are used. `$ignoreOwnReservation`
     * leaves the line's sibling lines out of the clash, for callers that check
     * a whole reservation's final rooms themselves (a swap holds both rooms
     * until it is written).
     *
     * @param  array{0: string, 1: string}|null  $nights
     */
    public static function reason(ReservationRoom $line, ?Room $room, bool $allowUpgrade = false, ?array $nights = null, bool $ignoreOwnReservation = false): ?string
    {
        if (! $room || $room->hotel_id !== $line->hotel_id) {
            return 'The selected room is not available.';
        }

        if ($room->room_type_id !== $line->room_type_id && ! $allowUpgrade) {
            return "Room {$room->room_number} is not of the line's room type; send upgrade to place it anyway.";
        }

        if ($nights === null) {
            $reservation = $line->reservation()->withoutGlobalScope('hotel')->withTrashed()->first();
            $nights = [$reservation->arrival_date->toDateString(), $reservation->departure_date->toDateString()];
        }

        $clash = $ignoreOwnReservation
            ? self::heldBy($room->id, $nights, exceptReservationId: $line->reservation_id)
            : self::heldBy($room->id, $nights, exceptLineId: $line->id);

        return $clash ? self::clashMessage($room->room_number, $clash) : null;
    }

    /**
     * The clash message for a room held by `$roomId` on any of `$nights` by
     * a reservation other than `$exceptReservationId`, or null when free.
     * For callers that have a room id, not a Room (new lines, date edits).
     *
     * @param  array{0: string, 1: string}  $nights
     */
    public static function clash(string $roomId, array $nights, ?string $exceptReservationId = null): ?string
    {
        if (! $code = self::heldBy($roomId, $nights, $exceptReservationId)) {
            return null;
        }

        return self::clashMessage((string) Room::withoutGlobalScope('hotel')->whereKey($roomId)->value('room_number'), $code);
    }

    public static function clashMessage(string $roomNumber, string $code): string
    {
        return "Room {$roomNumber} is booked for {$code} on overlapping nights.";
    }

    /**
     * The code of another reservation holding `$roomId` on any of `$nights`
     * ([arrival, departure]), or null when the room is free then.
     *
     * @param  array{0: string, 1: string}  $nights
     */
    public static function heldBy(string $roomId, array $nights, ?string $exceptReservationId = null, ?string $exceptLineId = null): ?string
    {
        return self::holdingLines()
            ->where('reservation_rooms.room_id', $roomId)
            ->when($exceptReservationId, fn ($query) => $query->where('reservation_rooms.reservation_id', '!=', $exceptReservationId))
            ->when($exceptLineId, fn ($query) => $query->where('reservation_rooms.id', '!=', $exceptLineId))
            ->where('reservations.arrival_date', '<', $nights[1])
            ->where('reservations.departure_date', '>', $nights[0])
            ->value('reservations.reservation_id');
    }

    /**
     * Rejects a room change on a line that can no longer change room: a
     * cancelled line, a reservation that holds no inventory, or a room whose
     * guest left or never came. Past stays keep their room as recorded.
     *
     * `$reservationStatus` and `$stayStatus` save the lookups for a caller
     * that already holds them locked.
     *
     * @throws ValidationException on `$key`
     */
    public static function assertChangeable(ReservationRoom $line, string $key, ?ReservationStatus $reservationStatus = null, StayStatus|false|null $stayStatus = false): void
    {
        if ($line->status === ReservationRoomStatus::CANCELLED) {
            throw ValidationException::withMessages([$key => 'This room line is cancelled.']);
        }

        $status = $reservationStatus ?? $line->reservation()->withoutGlobalScope('hotel')->value('status');

        if (! in_array($status, ReservationStatus::holdingInventory(), true)) {
            throw ValidationException::withMessages([$key => "Room changes are closed for this reservation ({$status->value})."]);
        }

        if ($stayStatus === false) {
            $stayStatus = Stay::withoutGlobalScope('hotel')->where('reservation_room_id', $line->id)->value('status');
        }

        $stayStatus = in_array($stayStatus, [StayStatus::DEPARTED, StayStatus::NO_SHOW], true) ? $stayStatus : null;

        if ($stayStatus) {
            throw ValidationException::withMessages([$key => "Room changes are closed for this room ({$stayStatus->value})."]);
        }
    }

    /**
     * Lines that hold their physical room, joined to their reservation: live,
     * not cancelled, on a reservation whose status holds inventory. The rule
     * every room-clash check shares (this class, the reservation import, and
     * the database trigger that keeps `reservation_rooms.held_nights`);
     * callers add the room, the nights and any line to leave out.
     */
    public static function holdingLines(): Builder
    {
        $holding = array_map(fn (ReservationStatus $status) => $status->value, ReservationStatus::holdingInventory());

        return ReservationRoom::withoutGlobalScope('hotel')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->whereNull('reservation_rooms.deleted_at')
            ->whereNull('reservations.deleted_at')
            ->whereIn('reservations.status', $holding)
            // A line whose guest never came, or already left, holds the room
            // no longer (SPEC-012), as in availability.
            ->whereNotExists(fn ($stay) => $stay->from('stays')
                ->whereColumn('stays.reservation_room_id', 'reservation_rooms.id')
                ->whereNull('stays.deleted_at')
                ->whereIn('stays.status', [StayStatus::NO_SHOW->value, StayStatus::DEPARTED->value]));
    }

    /**
     * Every pair of lines holding one room on a shared night, across all
     * hotels: what the `reservation_rooms_no_overlap` constraint forbids.
     * Read by `rooms:overlaps` before the constraint is added and after.
     *
     * @return Collection<int, object{hotel: string, room_number: string, first: string, second: string, from: string, to: string}>
     */
    public static function overlaps(): Collection
    {
        $held = self::holdingLines()
            ->whereNotNull('reservation_rooms.room_id')
            ->select([
                'reservation_rooms.id',
                'reservation_rooms.hotel_id',
                'reservation_rooms.room_id',
                'reservations.reservation_id as code',
                'reservations.arrival_date',
                'reservations.departure_date',
            ])
            ->toBase();

        return DB::query()
            ->fromSub($held, 'a')
            ->joinSub($held, 'b', fn ($join) => $join
                ->on('a.room_id', '=', 'b.room_id')
                ->whereColumn('a.id', '<', 'b.id')
                ->whereColumn('a.arrival_date', '<', 'b.departure_date')
                ->whereColumn('b.arrival_date', '<', 'a.departure_date'))
            ->join('rooms', 'rooms.id', '=', 'a.room_id')
            ->join('hotels', 'hotels.id', '=', 'a.hotel_id')
            ->orderBy('hotels.name')
            ->orderBy('rooms.room_number')
            ->selectRaw('hotels.name as hotel, rooms.room_number, a.code as first, b.code as second, '
                .'greatest(a.arrival_date, b.arrival_date) as "from", least(a.departure_date, b.departure_date) as "to"')
            ->get();
    }

    /**
     * @throws ValidationException on `$key`
     */
    public static function assertAssignable(ReservationRoom $line, ?Room $room, string $key, bool $allowUpgrade = false): void
    {
        if ($reason = self::reason($line, $room, $allowUpgrade)) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }
}
