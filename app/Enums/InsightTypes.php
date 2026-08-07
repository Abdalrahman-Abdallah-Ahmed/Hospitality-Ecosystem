<?php

namespace App\Enums;

enum InsightTypes: string
{
    case GENERAL = 'general';
    case RESERVATION = 'reservation';
    case TASK = 'task';
}
