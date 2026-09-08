<?php

namespace App\Enums;

/**
 * How a room stands with housekeeping, independent of whether it is sold.
 * An occupied room can be clean or dirty; a blocked room is out of order
 * (a fault, a leak, an unfinished repair) and must not be assigned at all.
 */
enum HousekeepingStatusesEnum: string
{
    case CLEAN = 'clean';
    case DIRTY = 'dirty';
    case BLOCKED = 'blocked';
}
