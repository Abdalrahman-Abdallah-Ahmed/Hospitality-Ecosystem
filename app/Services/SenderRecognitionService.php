<?php

namespace App\Services;

use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Support\RecognizedSender;

class SenderRecognitionService
{
    /**
     * Recognize who is messaging based on their WhatsApp phone number.
     *
     * A phone number matching a user is treated as an admin, a phone number
     * matching a guest is treated as a guest (hotel resolved through their
     * reservation), and anything else is unknown.
     */
    public function resolve(string $phoneNumber): RecognizedSender
    {
        $user = User::where('phone_number', $phoneNumber)
        ->where('role', UserRole::ADMIN)
        ->first();

        if ($user) {
            return new RecognizedSender(
                type: SenderType::ADMIN,
                sender: $user,
                hotelId: $user->hotel?->id,
            );
        }

        $guest = Guest::where('phone_number', $phoneNumber)->first();

        if ($guest) {
            $reservation = $this->relevantReservationFor($guest);

            return new RecognizedSender(
                type: SenderType::GUEST,
                sender: $guest,
                hotelId: $reservation?->hotel_id ?? $guest->hotel_id,
                reservation: $reservation,
            );
        }

        return new RecognizedSender(type: SenderType::UNKNOWN);
    }

    /**
     * Prefer the guest's current in-house stay, then their next upcoming
     * reservation, then fall back to their most recent past one.
     */
    private function relevantReservationFor(Guest $guest): ?Reservation
    {
        $today = now()->toDateString();

        return $guest->reservations()
                ->whereDate('arrival_date', '<=', $today)
                ->whereDate('departure_date', '>=', $today)
                ->latest('arrival_date')
                ->first()
            ?? $guest->reservations()
                ->whereDate('arrival_date', '>=', $today)
                ->oldest('arrival_date')
                ->first()
            ?? $guest->reservations()
                ->latest('arrival_date')
                ->first();
    }
}
