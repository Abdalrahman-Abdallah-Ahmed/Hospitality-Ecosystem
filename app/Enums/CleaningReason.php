<?php

namespace App\Enums;

/**
 * Why a cleaning task exists.
 */
enum CleaningReason: string
{
    case CHECK_OUT = 'check_out';
    case STAY_OVER = 'stay_over';
    case RE_CLEAN = 're_clean';
    case RETURN_TO_SERVICE = 'return_to_service';
    case MANUAL = 'manual';
}
