<?php

namespace App\Enums;

/**
 * The lifecycle of a commitment. Note what is absent: nothing here refers to
 * money. A booking's status is never derived from whether a transaction
 * exists, in either direction — see BookingService.
 */
enum BookingStatus: string
{
    case PENDING = 'pending';       // guest accepted, slot not yet confirmed
    case CONFIRMED = 'confirmed';   // slot held, guest expected
    case REALISED = 'realised';     // guest attended / consumed
    case NO_SHOW = 'no_show';       // confirmed, guest never came
    case CANCELLED = 'cancelled';   // cancelled before the date
}
