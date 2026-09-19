<?php

namespace App\Enums;

/**
 * Why a guest-related task exists. A different question from who created it
 * (tasks.created_by), which is why it is its own column.
 */
enum GuestSignal: string
{
    case ESCALATION = 'escalation';                 // EscalateToHumanTool
    case SERVICE_REQUEST = 'service_request';       // something is needed or broken
    case BOOKING_FOLLOW_UP = 'booking_follow_up';   // staff to help an interested guest book — a positive signal
}
