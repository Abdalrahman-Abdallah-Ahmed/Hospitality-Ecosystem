<?php

namespace App\Enums;

enum CreatedBy: string
{
    case AI = 'ai';
    case SYSTEM = 'system';
    case GUEST = 'guest';
    case MAINTENANCE_SCHEDULE = 'maintenance_schedule';
    case MANUAL = 'manual';
}
