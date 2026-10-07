<?php

namespace App\Ai\Tools\Concerns;

use App\Enums\StayStatus;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Support\Collection;

/**
 * Which of the guest's reservations and stays a request is about. Used by the
 * Concierge's request tools, which hold the guest, the hotel and the
 * reservation sender recognition resolved (nullable).
 */
trait ResolvesGuestStay
{
    private ?Reservation $activeReservation = null;

    private bool $activeReservationResolved = false;

    /**
     * The reservation a request is filed against: the resolved one when it is
     * current or upcoming, otherwise any other live reservation of the guest's
     * at this hotel. Recognition can pick a cancelled or checked-out
     * reservation over a live one, which must not lock the guest out.
     */
    protected function activeReservation(): ?Reservation
    {
        if (! $this->activeReservationResolved) {
            $this->activeReservation = $this->reservation?->isActive()
                ? $this->reservation
                : Reservation::activeFor($this->hotel, $this->guest);
            $this->activeReservationResolved = true;
        }

        return $this->activeReservation;
    }

    /**
     * Service, maintenance and room-change requests need a current or
     * upcoming reservation (SPEC-007 FR-030, R6). A guest with only past stays
     * gets the text to hand back instead; escalation is never gated.
     */
    protected function requiresActiveReservation(): ?string
    {
        if ($this->activeReservation()) {
            return null;
        }

        return "This needs a current or upcoming reservation at {$this->hotel->name}. The guest has none, so nothing was filed. Offer to connect them with staff instead.";
    }

    /**
     * The guest's in-house stays on this reservation, with their rooms.
     *
     * @return Collection<int, Stay>
     */
    protected function inHouseStays(): Collection
    {
        if (! $reservation = $this->activeReservation()) {
            return collect();
        }

        return Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->with('room')
            ->get();
    }

    /**
     * The stay the request is about (FR-019 of SPEC-005): the guest's only
     * room in the house, or the one whose number they gave. None when they
     * are not in yet, or are in several rooms and did not say which.
     */
    protected function stayFor(string $roomNumber): ?Stay
    {
        $inHouse = $this->inHouseStays();

        if ($inHouse->count() === 1) {
            return $inHouse->first();
        }

        $roomNumber = trim($roomNumber);

        return $roomNumber === '' ? null : $inHouse->first(fn (Stay $stay) => $stay->room?->room_number === $roomNumber);
    }

    /**
     * Before the guest is in, the request still names the room they are
     * booked into, when there is exactly one; several rooms and none named
     * leave it to staff.
     */
    protected function fallbackRoomId(): ?string
    {
        if (! $reservation = $this->activeReservation()) {
            return null;
        }

        return $this->inHouseStays()->count() > 1 ? null : $reservation->primaryRoomId();
    }

    /**
     * The guest is in several rooms and did not name one of them: the tool
     * should ask rather than guess. Returns the text to hand back, or null.
     */
    protected function askWhichRoom(string $roomNumber): ?string
    {
        $inHouse = $this->inHouseStays();

        if ($inHouse->count() < 2 || $this->stayFor($roomNumber)) {
            return null;
        }

        $numbers = $inHouse->map(fn (Stay $stay) => $stay->room?->room_number)->filter()->sort()->values();

        return 'The guest is in rooms '.$numbers->implode(' and ').'. Ask which room before filing.';
    }
}
