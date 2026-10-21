<?php

namespace App\Support\Reservations;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Stay;
use Illuminate\Support\Collection;

/**
 * The one lock order for actions on a reservation's stays — the reservation,
 * then its lines and stays — shared by check-in/out (StayLifecycleService)
 * and no-shows (NoShowService), so a no-show racing a check-in on the same
 * stay waits instead of deadlocking. Rooms, when needed, are locked after.
 * Call inside a transaction.
 */
class ReservationLock
{
    /**
     * Returns fresh copies, so every check after it reads what is committed
     * now, not what the caller loaded before waiting.
     *
     * @return array{Reservation, Collection<string, Stay>}
     */
    public static function lock(Reservation $reservation): array
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
}
