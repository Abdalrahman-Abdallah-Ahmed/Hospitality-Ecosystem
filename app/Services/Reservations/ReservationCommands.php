<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\Reservations\ReservationCreator;
use App\Support\Reservations\RoomHoldConflict;
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
     * @param  bool  $allowInHouseMove  only RoomAssignmentService::move() moves a guest in the house
     * @param  list<string>  $upgradeLineIds  lines RoomAssignmentService cleared for a room of another type
     */
    public function update(Reservation $reservation, array $attributes, ?array $rooms, bool $capacityOverride = false, bool $overbookOverride = false, bool $allowInHouseMove = false, array $upgradeLineIds = []): Reservation
    {
        return RoomHoldConflict::rethrow(fn () => DB::transaction(fn () => ReservationCreator::update($reservation, $attributes, $rooms, $capacityOverride, $overbookOverride, $allowInHouseMove, $upgradeLineIds)));
    }

    /**
     * Cancel the reservation and, with it, every live line.
     */
    public function cancel(Reservation $reservation): Reservation
    {
        return $this->update($reservation, ['status' => ReservationStatus::CANCELLED->value], null);
    }

    /**
     * Put rooms on (or take them off) existing lines through the room
     * assignment rules (spec 020). `$roomByLine` maps a live line's id to a
     * room id, or null to clear it; lines not named keep their room.
     * `$upgradeLineIds` may take a room of another type.
     *
     * @param  array<string, string|null>  $roomByLine
     * @param  list<string>  $upgradeLineIds
     * @return array{reservation: Reservation, warnings: list<string>}
     */
    public function assignRooms(Reservation $reservation, array $roomByLine, array $upgradeLineIds = []): array
    {
        return app(RoomAssignmentService::class)->assign($reservation, collect($roomByLine)
            ->map(fn (?string $roomId, string $lineId) => [
                'line_id' => $lineId,
                'room_id' => $roomId,
                'upgrade' => in_array($lineId, $upgradeLineIds, true),
            ])
            ->values()
            ->all());
    }
}
