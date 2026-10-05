<?php

namespace App\Enums;

/**
 * How a room stands with housekeeping, independent of whether it can be sold
 * (D5). Out of order is a room status, not a housekeeping one. Moved only by
 * HousekeepingService.
 */
enum HousekeepingStatusesEnum: string
{
    case DIRTY = 'dirty';
    case CLEANING = 'cleaning';
    case CLEAN = 'clean';
    case INSPECTED = 'inspected';
}
