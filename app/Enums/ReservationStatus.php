<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CHECKED_IN = 'checked_in';
    case CHECKED_OUT = 'checked_out';
    case CANCELLED = 'cancelled';

    /**
     * The statuses whose live room lines take a room out of availability:
     * booked (tentative or confirmed) or in the house. A pending booking holds
     * its rooms exactly like a confirmed one, so it is never sold twice.
     * Checked-out and cancelled reservations hold nothing, and neither will
     * SPEC-012's no_show.
     *
     * @return list<self>
     */
    public static function holdingInventory(): array
    {
        return [self::PENDING, self::CONFIRMED, self::CHECKED_IN];
    }
}
