<?php

namespace App\Support\Reservations;

use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Models\ReservationRoom;
use App\Models\Room;
use Illuminate\Validation\ValidationException;

/**
 * Whether a physical room may be put on a reservation line: a room of the
 * line's hotel and room type that no other live line holds on any of the
 * line's nights. Used by check-in when the desk names a room for an
 * unassigned line (FR-007a).
 *
 * SPEC-021: staff assignment and reassignment reuse these rules, and add the
 * database exclusion constraint behind them.
 */
class RoomAssignmentRules
{
    /**
     * The reason `$room` cannot go on `$line`, or null when it can.
     */
    public static function reason(ReservationRoom $line, ?Room $room): ?string
    {
        if (! $room || $room->hotel_id !== $line->hotel_id) {
            return 'The selected room is not available.';
        }

        if ($room->room_type_id !== $line->room_type_id) {
            return "Room {$room->room_number} is not of the line's room type.";
        }

        $reservation = $line->reservation()->withoutGlobalScope('hotel')->withTrashed()->first();
        $holding = array_map(fn (ReservationStatus $status) => $status->value, ReservationStatus::holdingInventory());

        $clash = ReservationRoom::withoutGlobalScope('hotel')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->where('reservation_rooms.room_id', $room->id)
            ->where('reservation_rooms.id', '!=', $line->id)
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->whereNull('reservation_rooms.deleted_at')
            ->whereNull('reservations.deleted_at')
            ->whereIn('reservations.status', $holding)
            ->where('reservations.arrival_date', '<', $reservation->departure_date)
            ->where('reservations.departure_date', '>', $reservation->arrival_date)
            ->value('reservations.reservation_id');

        return $clash ? "Room {$room->room_number} is booked for {$clash} on overlapping nights." : null;
    }

    /**
     * @throws ValidationException on `$key`
     */
    public static function assertAssignable(ReservationRoom $line, ?Room $room, string $key): void
    {
        if ($reason = self::reason($line, $room)) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }
}
