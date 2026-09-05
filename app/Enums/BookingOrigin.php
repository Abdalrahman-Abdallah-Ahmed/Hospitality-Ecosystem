<?php

namespace App\Enums;

/**
 * Where the booking came from.
 *
 * This is what lets you compare recommended bookings against organic ones —
 * without it you cannot tell whether the agent is creating demand or merely
 * recording demand that already existed, which is the first question any
 * manager will ask.
 */
enum BookingOrigin: string
{
    case RECOMMENDATION = 'recommendation';  // followed an agent recommendation
    case GUEST_REQUEST = 'guest_request';    // guest asked unprompted
    case STAFF = 'staff';                    // staff booked it directly
    case IMPORT = 'import';                  // loaded from another system
}
