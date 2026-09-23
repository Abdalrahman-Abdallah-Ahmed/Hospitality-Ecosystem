<?php

namespace App\Enums;

/**
 * The state of one booked room unit on a reservation. Later specs
 * (assignment, check-in/out, no-show) add cases.
 */
enum ReservationRoomStatus: string
{
    case RESERVED = 'reserved';     // booked; may or may not have a physical room
    case CANCELLED = 'cancelled';   // removed from the reservation; kept for history, holds no room
}
