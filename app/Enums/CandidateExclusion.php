<?php

namespace App\Enums;

/**
 * Why an active activity was left off a turn's candidate list.
 */
enum CandidateExclusion: string
{
    case ALREADY_BOOKED = 'already_booked';             // non-cancelled booking for this activity, this stay
    case NOT_ENOUGH_DAYS = 'not_enough_days';           // no run of duration_days consecutive usable dates before departure
    case CLOSED_ON_ALL_DATES = 'closed_on_all_dates';   // timeframe: no remaining date is open
    case NO_CAPACITY = 'no_capacity';                   // full on every remaining open date (known capacity only)
    case CLASHES_ON_ALL_DATES = 'clashes_on_all_dates'; // a same-category booking on every date it still has room
    case OUTSIDE_INTEREST = 'outside_interest';         // the guest asked about a different category
}
