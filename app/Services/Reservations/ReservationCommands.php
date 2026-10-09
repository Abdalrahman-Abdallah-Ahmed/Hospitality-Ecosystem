<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Support\Facades\DB;

/**
 * Changing a reservation, for the staff API and the Admin AI alike
 * (SPEC-055 R4, R5). Everything goes through ReservationCreator::update, so
 * both paths get the same line rules, room-assignment rules, party capacity
 * check, availability guard and stay sync, with the same messages.
 *
 * Guards that only a staff request can trip (a client-chosen guest_id or
 * reservation code, the deprecated lifecycle status, the overbooking
 * override) stay in ReservationController: the AI never sends those fields.
 */
class ReservationCommands
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>|null  $rooms  the full desired list of live lines, or null to leave them
     */
    public function update(Reservation $reservation, array $attributes, ?array $rooms, bool $capacityOverride = false, bool $overbookOverride = false): Reservation
    {
        return DB::transaction(fn () => ReservationCreator::update($reservation, $attributes, $rooms, $capacityOverride, $overbookOverride));
    }

    /**
     * Cancel the reservation and, with it, every live line.
     */
    public function cancel(Reservation $reservation): Reservation
    {
        return $this->update($reservation, ['status' => ReservationStatus::CANCELLED->value], null);
    }

    /**
     * Put rooms on (or take them off) existing lines. `$roomByLine` maps a
     * live line's id to a room id, or null to clear it; lines not named keep
     * their room. Every live line is passed on, so none is cancelled.
     *
     * @param  array<string, string|null>  $roomByLine
     */
    public function assignRooms(Reservation $reservation, array $roomByLine): Reservation
    {
        $rooms = $reservation->reservationRooms()->active()->get()
            ->map(fn (ReservationRoom $line) => [
                'id' => $line->id,
                'room_id' => array_key_exists($line->id, $roomByLine) ? $roomByLine[$line->id] : $line->room_id,
            ])
            ->values()
            ->all();

        return $this->update($reservation, [], $rooms);
    }
}
