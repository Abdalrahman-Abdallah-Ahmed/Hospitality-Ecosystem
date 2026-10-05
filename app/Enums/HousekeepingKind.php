<?php

namespace App\Enums;

/**
 * The tasks that move a room's housekeeping status. Set from the hotel's
 * cleaning and inspection categories when a task is written; any other task,
 * including other Housekeeping-team categories, leaves the room alone.
 */
enum HousekeepingKind: string
{
    case CLEANING = 'cleaning';
    case INSPECTION = 'inspection';
}
