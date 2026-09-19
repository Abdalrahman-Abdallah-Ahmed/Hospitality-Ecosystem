<?php

namespace App\Enums;

/**
 * Something in the guest's current message that makes a suggestion welcome.
 * The list is the owner's decision (pitching plan §15, D-2); adding a case is
 * a one-line, reviewed change.
 */
enum PitchOpening: string
{
    // Explicit — the guest asked for a suggestion.
    case ASKS_WHAT_TO_DO = 'asks_what_to_do';
    case ASKS_ABOUT_ACTIVITIES = 'asks_about_activities';

    // Contextual — the guest opened a door without asking.
    case BEACH_OR_POOL = 'beach_or_pool';
    case EVENING_PLANS = 'evening_plans';
    case BOREDOM = 'boredom';
    case CHILDREN = 'children';
    case WEATHER = 'weather';

    /**
     * A guest who asks for a suggestion is not being pushed, so the pitch cap
     * and the one-refusal rule do not apply to them. Every other gate does.
     */
    public function isExplicitRequest(): bool
    {
        return in_array($this, [self::ASKS_WHAT_TO_DO, self::ASKS_ABOUT_ACTIVITIES], true);
    }
}
