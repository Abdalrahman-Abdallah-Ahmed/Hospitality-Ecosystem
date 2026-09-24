<?php

namespace App\Ai\Tools\Concerns;

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the Admin AI's check-in and check-out tools: resolve what the
 * model names (a reservation code, room numbers) within the tool's own
 * hotel only, and turn a refusal into the same words staff would see.
 */
trait FindsReservationStays
{
    private function findReservation(Hotel $hotel, string $code): ?Reservation
    {
        return $code === '' ? null : Reservation::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('reservation_id', $code)
            ->first();
    }

    /**
     * @return Collection<int, Stay>
     */
    private function staysOf(Reservation $reservation): Collection
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->with(['room', 'reservationRoom.roomType', 'reservationRoom.room'])
            ->get();
    }

    /**
     * @param  array<int, mixed>  $numbers
     * @return list<string>
     */
    private function roomNumbers(array $numbers): array
    {
        return array_values(array_filter(array_map(fn ($number) => trim((string) $number), $numbers), fn ($number) => $number !== ''));
    }

    private function refusal(ValidationException $e): string
    {
        return 'Not done: '.collect($e->errors())->flatten()->unique()->implode(' ');
    }
}
