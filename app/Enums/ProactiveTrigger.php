<?php

namespace App\Enums;

/**
 * What may cause the Concierge to message a guest first (SPEC-073).
 */
enum ProactiveTrigger: string
{
    case FIRST_MORNING = 'first_morning';                       // the first morning after check-in
    case MID_STAY = 'mid_stay';                                 // the middle of a stay of 4+ nights
    case UPCOMING_ACTIVITY = 'upcoming_activity';               // a reminder of a confirmed booking
    case RECOMMENDATION_APPROVED = 'recommendation_approved';   // a newly approved recommendation

    /**
     * Whether the message offers a recommendation. A booking reminder is
     * information about something the guest already chose, not a pitch.
     */
    public function isPitch(): bool
    {
        return $this !== self::UPCOMING_ACTIVITY;
    }
}
