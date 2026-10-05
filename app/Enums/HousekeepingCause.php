<?php

namespace App\Enums;

/**
 * What moved a room's housekeeping or service status, as the audit trail
 * records it.
 */
enum HousekeepingCause: string
{
    case TASK = 'task';
    case CHECK_OUT = 'check_out';
    case START_OF_DAY = 'start_of_day';
    case INSPECTION = 'inspection';
    case ISSUE = 'issue';
    case RETURN_TO_SERVICE = 'return_to_service';
    case MANUAL = 'manual';
    case MIGRATION = 'migration';
}
