<?php

namespace App\Support\Pitching;

use App\Models\Activity;
use Carbon\CarbonInterface;

/**
 * When an activity can be done, from its timeframe columns. Pure: no
 * queries, so every boundary can be tested case by case.
 *
 * All dates are Y-m-d strings in the hotel's own timezone, the same zone the
 * timeframe columns are written in.
 */
final class ActivityTimeframe
{
    /**
     * The dates from $from to $until (both inclusive) on which the activity
     * is open. A date is open when:
     *
     *  - it is inside the season (a null bound is no limit);
     *  - it is not inside any unavailable period (both ends inclusive);
     *  - operating_hours is null, or that weekday has at least one slot;
     *  - for today only: operating_hours is null, or a slot ends after the
     *    current local time. A slot ending exactly now is over.
     *
     * @return list<string>
     */
    public static function openDates(Activity $activity, CarbonInterface $from, CarbonInterface $until, CarbonInterface $now): array
    {
        $open = [];
        $today = $now->toDateString();
        $last = $until->toDateString();

        for ($date = $from->copy()->startOfDay(); $date->toDateString() <= $last; $date = $date->addDay()) {
            if (self::isOpenOn($activity, $date, $date->toDateString() === $today ? $now->format('H:i') : null)) {
                $open[] = $date->toDateString();
            }
        }

        return $open;
    }

    /**
     * The dates that begin a run of $durationDays consecutive dates, all of
     * them in $usableDates. A single-day activity can start on any usable date.
     *
     * @param  list<string>  $usableDates
     * @return list<string>
     */
    public static function startDates(array $usableDates, int $durationDays): array
    {
        $usable = array_flip($usableDates);

        return array_values(array_filter($usableDates, function (string $start) use ($usable, $durationDays) {
            for ($offset = 1; $offset < $durationDays; $offset++) {
                if (! isset($usable[date('Y-m-d', strtotime("{$start} +{$offset} days"))])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The length of the longest run of consecutive dates, for explaining why
     * a multi-day activity did not fit. 0 for no dates.
     *
     * @param  list<string>  $dates  ascending
     */
    public static function longestRun(array $dates): int
    {
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            $run = $previous !== null && $date === date('Y-m-d', strtotime("{$previous} +1 day")) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * @param  ?string  $afterTime  "H:i" on today's date, else null
     */
    private static function isOpenOn(Activity $activity, CarbonInterface $date, ?string $afterTime): bool
    {
        $day = $date->toDateString();

        if ($activity->available_from && $day < $activity->available_from->toDateString()) {
            return false;
        }

        if ($activity->available_until && $day > $activity->available_until->toDateString()) {
            return false;
        }

        foreach ($activity->unavailable_periods ?? [] as $period) {
            if ($day >= $period['start_date'] && $day <= $period['end_date']) {
                return false;
            }
        }

        if ($activity->operating_hours === null) {
            return true;
        }

        $slots = $activity->operating_hours[strtolower($date->englishDayOfWeek)] ?? [];

        foreach ($slots as $slot) {
            if ($afterTime === null || $slot['end'] > $afterTime) {
                return true;
            }
        }

        return false;
    }
}
