<?php

namespace App\Enums;

/**
 * Why an activity cannot take a booking for a date, in the order the checks
 * run (research R5): the first failing rule is the one reported.
 */
enum ActivityUnavailableReason: string
{
    case INACTIVE = 'inactive';                               // not offered, or removed from the catalogue
    case PAST_DATE = 'past_date';                             // before the hotel's today
    case OUT_OF_SEASON = 'out_of_season';                     // outside available_from / available_until
    case CLOSURE_PERIOD = 'closure_period';                   // inside one of its unavailable periods
    case CLOSED_WEEKDAY = 'closed_weekday';                   // no opening window that weekday
    case TIME_REQUIRED = 'time_required';                     // it has windows that day, and no time was given
    case OUTSIDE_OPENING_HOURS = 'outside_opening_hours';     // the time is in none of that day's windows
    case PARTY_EXCEEDS_CAPACITY = 'party_exceeds_capacity';   // the party is bigger than a whole day's capacity
    case FULLY_BOOKED = 'fully_booked';                       // not enough places left that day
    case OUTSIDE_RESERVATION = 'outside_reservation';         // the Concierge only: not during the guest's reservation

    /**
     * Only a shortage of places can be overridden, and only by staff. A closed
     * activity stays closed whoever asks.
     */
    public function overridable(): bool
    {
        return $this === self::FULLY_BOOKED || $this === self::PARTY_EXCEEDS_CAPACITY;
    }
}
