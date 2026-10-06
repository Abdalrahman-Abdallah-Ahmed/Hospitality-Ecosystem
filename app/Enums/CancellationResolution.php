<?php

namespace App\Enums;

/**
 * How staff answered a guest's request to cancel a booking.
 */
enum CancellationResolution: string
{
    case APPROVED = 'approved';   // the booking was cancelled
    case DECLINED = 'declined';   // the booking stands; the note says why
}
