<?php

namespace App\Services\Vouchers;

use App\Enums\ReservationStatus;
use App\Models\Reservation;

/**
 * Which reservations can get a voucher (FR-004): a voucher proves a booking,
 * so cancelled and no-show reservations never get one. Checked when a
 * voucher is generated or emailed, and again by the email job at send time
 * (FR-029).
 */
class ReservationVoucherEligibility
{
    public function isEligible(Reservation $reservation): bool
    {
        return $this->reasonFor($reservation) === null;
    }

    /**
     * Why the reservation cannot get a voucher, e.g. `reservation_cancelled`,
     * or null when it can.
     */
    public function reasonFor(Reservation $reservation): ?string
    {
        return in_array($reservation->status, [ReservationStatus::CANCELLED, ReservationStatus::NO_SHOW], true)
            ? 'reservation_'.$reservation->status->value
            : null;
    }
}
