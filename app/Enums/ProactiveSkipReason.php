<?php

namespace App\Enums;

/**
 * Why a proactive message was not sent (yet). The deferring reasons move
 * the message to a later time — or end it, when that time would come after
 * the message stopped being worth sending. Every other reason ends it.
 */
enum ProactiveSkipReason: string
{
    case DISABLED = 'disabled';                                     // proactive messaging is off for the hotel
    case TRIGGER_DISABLED = 'trigger_disabled';
    case OPTED_OUT = 'opted_out';
    case ALREADY_PITCHED = 'already_pitched';                       // the reservation already had an unsolicited pitch
    case OUTSIDE_WINDOW = 'outside_window';                         // WhatsApp's 24-hour window is closed
    case NO_WHATSAPP = 'no_whatsapp';
    case NOT_IN_HOUSE = 'not_in_house';
    case DEPARTING = 'departing';
    case ESCALATED = 'escalated';
    case OPEN_COMPLAINT = 'open_complaint';
    case PITCHING_DISABLED = 'pitching_disabled';
    case PITCH_CAP = 'pitch_cap';
    case RETRY_USED = 'retry_used';
    case RETRY_WINDOW_CLOSED = 'retry_window_closed';
    case NO_CANDIDATE = 'no_candidate';
    case BOOKING_NOT_CONFIRMED = 'booking_not_confirmed';
    case RECOMMENDATION_NOT_OFFERABLE = 'recommendation_not_offerable';
    case MISSING_DATA = 'missing_data';
    case EXPIRED = 'expired';                                       // past valid_until
    case SEND_FAILED = 'send_failed';

    case QUIET_HOURS = 'quiet_hours';
    case DAILY_CAP = 'daily_cap';
    case GUEST_ACTIVE = 'guest_active';                             // the guest wrote in the last 30 minutes

    public function defers(): bool
    {
        return in_array($this, [self::QUIET_HOURS, self::DAILY_CAP, self::GUEST_ACTIVE], true);
    }
}
