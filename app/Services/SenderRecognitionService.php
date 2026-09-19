<?php

namespace App\Services;

use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Support\PhoneNumber;
use App\Support\RecognizedSender;
use Illuminate\Support\Collection;

class SenderRecognitionService
{
    /**
     * Recognize who is messaging based on their WhatsApp phone number.
     *
     * A phone number matching an admin is treated as an admin, a phone number
     * matching a guest is treated as a guest (hotel resolved through their
     * reservation), and anything else is unknown. This is the single place
     * that decides "who is this" for the WhatsApp flow — including whether
     * an admin has actually completed device pairing — so callers never
     * need a separate pairing check.
     *
     * Numbers are compared by their digits on both sides: Meta sends digits
     * only, while users and guests hold whatever was typed ("+20 115 …").
     */
    public function resolve(string $phoneNumber): RecognizedSender
    {
        $digits = PhoneNumber::digits($phoneNumber);

        if ($digits === null) {
            return new RecognizedSender(type: SenderType::UNKNOWN);
        }

        $user = User::whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])
            ->where('role', UserRole::ADMIN)
            ->orderBy('created_at')
            ->first();

        if ($user) {
            return new RecognizedSender(
                type: SenderType::ADMIN,
                sender: $user,
                hotelId: $user->hotel?->id,
                devicePaired: WhatsAppDevice::whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])
                    ->where('status', 'active')
                    ->exists(),
            );
        }

        $guests = Guest::whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])->get();

        if ($guests->isEmpty()) {
            return new RecognizedSender(type: SenderType::UNKNOWN);
        }

        [$guest, $reservation] = $this->mostRelevant($guests);

        return new RecognizedSender(
            type: SenderType::GUEST,
            sender: $guest,
            hotelId: $reservation?->hotel_id ?? $guest->hotel_id,
            reservation: $reservation,
        );
    }

    /**
     * One number can belong to several guest rows: guests are per hotel, and
     * every client hotel shares the platform's WhatsApp number, so a person
     * who stayed at two of them has two records. Picking one arbitrarily
     * would hand them the other hotel's concierge and data, so the choice is
     * made by the stay they are most plausibly writing about: in-house now,
     * then the next arrival, then the most recent past stay. A number with
     * no reservations at all goes to the most recently created guest.
     * Every tie is broken by id, so the same inputs always give the same answer.
     *
     * @param  Collection<int, Guest>  $guests
     * @return array{0: Guest, 1: ?Reservation}
     */
    private function mostRelevant(Collection $guests): array
    {
        $reservation = Reservation::whereIn('guest_id', $guests->modelKeys())
            ->get()
            ->sort(fn (Reservation $a, Reservation $b) => $this->rank($a) <=> $this->rank($b))
            ->first();

        if ($reservation) {
            return [$guests->firstWhere('id', $reservation->guest_id), $reservation];
        }

        $guest = $guests
            ->sort(fn (Guest $a, Guest $b) => [$b->created_at, $b->id] <=> [$a->created_at, $a->id])
            ->first();

        return [$guest, null];
    }

    /**
     * Sort key: lower sorts first.
     *
     * @return array{int, int, string}
     */
    private function rank(Reservation $reservation): array
    {
        $today = now()->toDateString();
        $arrival = $reservation->arrival_date->toDateString();
        $departure = $reservation->departure_date->toDateString();
        $arrivalTimestamp = strtotime($arrival);

        return match (true) {
            // In-house: the latest arrival wins if stays overlap.
            $arrival <= $today && $departure >= $today => [0, -$arrivalTimestamp, $reservation->id],
            // Upcoming: the soonest arrival wins.
            $arrival > $today => [1, $arrivalTimestamp, $reservation->id],
            // Past: the most recent stay wins.
            default => [2, -$arrivalTimestamp, $reservation->id],
        };
    }
}
