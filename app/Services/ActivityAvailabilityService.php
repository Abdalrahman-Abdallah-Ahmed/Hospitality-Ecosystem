<?php

namespace App\Services;

use App\Enums\ActivityUnavailableReason;
use App\Enums\ActorKind;
use App\Enums\BookingStatus;
use App\Exceptions\ActivityUnavailableException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Hotel;
use App\Support\Activities\DayAvailability;
use App\Support\Audit\EventLogger;
use Carbon\CarbonImmutable;

/**
 * Whether an activity can take a party on a date, and how many places each
 * date has left. The staff endpoints, the AI tools and BookingService all
 * read it from here, so the lookup and the check can never disagree.
 *
 * The rules come from the activity's own columns (SPEC-041):
 *
 * - its season (available_from / available_until) and closure periods;
 * - its opening windows per weekday, in the hotel's local time. Null hours
 *   mean open all day; a weekday left out, or given no windows, is closed;
 * - its daily capacity, shared by every window that day (null = unlimited).
 *
 * A booking holds `pax` places on every day from its scheduled_date to its
 * last_date while it is pending, confirmed or realised. Nothing here is
 * stored.
 *
 * Every query names the hotel: AI tools run without tenant context.
 */
class ActivityAvailabilityService
{
    /**
     * The longest range the staff lookup answers in one call.
     */
    public const MAX_RANGE_DAYS = 31;

    /**
     * The AI tools answer at most this many days per call, so a question
     * never puts a month of rows into the model's context.
     */
    public const AI_MAX_RANGE_DAYS = 14;

    /**
     * The hotel's current date, which decides what counts as "past".
     */
    public function today(Hotel $hotel): string
    {
        return CarbonImmutable::now($this->timezone($hotel))->toDateString();
    }

    /**
     * Locks the given activities until the surrounding transaction ends, so
     * two bookings for the last places are checked one after the other.
     * Locked in id order so a booking moved between two activities cannot
     * deadlock with one moved the other way.
     *
     * @param  iterable<int, string|null>  $activityIds
     */
    public function lock(iterable $activityIds): void
    {
        $ids = collect($activityIds)->filter()->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return;
        }

        Activity::withoutGlobalScope('hotel')->withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * One entry per date in [from, to]. Two queries whatever the length.
     *
     * @return list<DayAvailability>
     */
    public function range(Activity $activity, string $from, string $to): array
    {
        $hotel = $this->hotel($activity);
        $today = $this->today($hotel);
        $span = $this->span($activity);
        $last = CarbonImmutable::parse($to)->addDays($span - 1)->toDateString();
        $load = $this->bookedLoad($activity, $from, $last);

        $days = [];

        foreach ($this->dates($from, $to) as $date) {
            $days[] = $this->describeDay($activity, $date, $today, $load);
        }

        return $days;
    }

    public function day(Activity $activity, string $date): DayAvailability
    {
        return $this->range($activity, $date, $date)[0];
    }

    /**
     * Throws when the activity cannot take `$pax` people starting `$date` at
     * `$time`, with the first rule that fails (research R5). Returns null when
     * the booking fits, or the shortage it was let through with when staff
     * overrode capacity, so the caller can audit it.
     *
     * Only a shortage of places can be overridden, and never by an AI actor,
     * whatever it asks for.
     *
     * @param  bool  $checkPast  false when an edit leaves the date alone, so a
     *                           past booking can still have its party corrected
     * @return array{date: string, capacity: int, booked: int, pax: int}|null
     *
     * @throws ActivityUnavailableException
     */
    public function assertBookable(
        Activity $activity,
        string $date,
        ?string $time,
        int $pax,
        ?string $ignoreBookingId = null,
        bool $override = false,
        bool $checkPast = true,
    ): ?array {
        $hotel = $this->hotel($activity);
        $time = $time !== null ? substr($time, 0, 5) : null;
        $covered = $this->covered($activity, $date);
        $load = $activity->daily_capacity !== null
            ? $this->bookedLoad($activity, $covered[0], end($covered), $ignoreBookingId)
            : [];

        $failure = $this->firstFailure($activity, $date, $time, $pax, $load, $checkPast ? $this->today($hotel) : null);

        if ($failure === null) {
            return null;
        }

        [$reason, $failedDate, $details] = $failure;

        if ($reason->overridable() && $override && EventLogger::currentActorKind() !== ActorKind::AI_AGENT) {
            return [
                'date' => $failedDate,
                'capacity' => (int) $activity->daily_capacity,
                'booked' => $details['booked'] ?? 0,
                'pax' => $pax,
            ];
        }

        throw ActivityUnavailableException::for($activity, $reason, $failedDate, [...$details, 'time' => $time, 'pax' => $pax]);
    }

    /**
     * Up to `$limit` dates in [from, until] that could take the party, nearest
     * to `$around` first (earlier date first on a tie). Times are not
     * checked: any open date has at least one window to start in.
     *
     * @return list<string>
     */
    public function nearestOpenDates(Activity $activity, string $around, int $pax, int $limit, string $from, string $until): array
    {
        if ($until < $from) {
            return [];
        }

        $last = CarbonImmutable::parse($until)->addDays($this->span($activity) - 1)->toDateString();
        $load = $this->bookedLoad($activity, $from, $last);
        $today = $this->today($this->hotel($activity));
        $aroundDay = CarbonImmutable::parse($around);

        return collect($this->dates($from, $until))
            ->filter(fn (string $date) => $date !== $around)
            ->sortBy(fn (string $date) => [abs($aroundDay->diffInDays(CarbonImmutable::parse($date))), $date])
            ->filter(fn (string $date) => $this->firstFailure($activity, $date, null, $pax, $load, $today, checkTime: false) === null)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The opening windows on a date's weekday. Empty when the activity has
     * no hours at all (open all day) or is closed that weekday; the caller
     * tells those apart by whether `operating_hours` is null.
     *
     * @return list<array{start: string, end: string}>
     */
    public function windowsOn(Activity $activity, string $date): array
    {
        if ($activity->operating_hours === null) {
            return [];
        }

        // The same lower-case English names ValidatesActivityTimeframe accepts.
        $weekday = strtolower(CarbonImmutable::parse($date)->englishDayOfWeek);

        return collect($activity->operating_hours[$weekday] ?? [])
            ->map(fn (array $window) => ['start' => substr($window['start'], 0, 5), 'end' => substr($window['end'], 0, 5)])
            ->sortBy('start')
            ->values()
            ->all();
    }

    /**
     * The dates a booking starting on `$date` covers.
     *
     * @return list<string>
     */
    public function covered(Activity $activity, string $date): array
    {
        $start = CarbonImmutable::parse($date);

        return collect(range(0, $this->span($activity) - 1))
            ->map(fn (int $offset) => $start->addDays($offset)->toDateString())
            ->all();
    }

    /**
     * The last date a booking starting on `$date` covers.
     */
    public function lastDate(?Activity $activity, string $date): string
    {
        return CarbonImmutable::parse($date)->addDays(($activity ? $this->span($activity) : 1) - 1)->toDateString();
    }

    /**
     * People held per date in [from, to] by bookings that hold capacity.
     *
     * @return array<string, int>
     */
    public function bookedLoad(Activity $activity, string $from, string $to, ?string $ignoreBookingId = null): array
    {
        $bookings = Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $activity->hotel_id)
            ->where('activity_id', $activity->id)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::holdingCapacity()))
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '<=', $to)
            ->where('last_date', '>=', $from)
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->get(['scheduled_date', 'last_date', 'pax']);

        $load = [];

        foreach ($bookings as $booking) {
            $first = max($booking->scheduled_date->toDateString(), $from);
            $last = min($booking->last_date->toDateString(), $to);

            foreach ($this->dates($first, $last) as $date) {
                $load[$date] = ($load[$date] ?? 0) + (int) $booking->pax;
            }
        }

        return $load;
    }

    /**
     * @param  array<string, int>  $load
     */
    private function describeDay(Activity $activity, string $date, string $today, array $load): DayAvailability
    {
        $closed = $this->closedReason($activity, $date);
        $capacity = $activity->daily_capacity;
        $remaining = $capacity === null
            ? null
            : min(array_map(fn (string $day) => $capacity - ($load[$day] ?? 0), $this->covered($activity, $date)));

        $reason = $closed[0] ?? null;

        // A multi-day activity is open on a date only when every day it covers is.
        if ($reason === null) {
            foreach (array_slice($this->covered($activity, $date), 1) as $day) {
                if ($dayClosed = $this->closedReason($activity, $day)) {
                    $reason = $dayClosed[0];
                    $closed = $dayClosed;
                    break;
                }
            }
        }

        $open = $reason === null;

        if ($open && $remaining !== null && $remaining <= 0) {
            $reason = ActivityUnavailableReason::FULLY_BOOKED;
        }

        return new DayAvailability(
            date: $date,
            open: $open,
            reason: $reason,
            closureReason: $closed[1] ?? null,
            windows: $this->windowsOn($activity, $date),
            capacity: $capacity,
            booked: $load[$date] ?? 0,
            remaining: $remaining,
            past: $date < $today,
        );
    }

    /**
     * The first rule a booking breaks, in research R5's order, or null.
     *
     * @param  array<string, int>  $load
     * @return array{0: ActivityUnavailableReason, 1: string, 2: array<string, mixed>}|null
     */
    private function firstFailure(Activity $activity, string $date, ?string $time, int $pax, array $load, ?string $today, bool $checkTime = true): ?array
    {
        $windows = $this->windowsOn($activity, $date);

        if (! $activity->is_active || $activity->trashed()) {
            return [ActivityUnavailableReason::INACTIVE, $date, []];
        }

        if ($today !== null && $date < $today) {
            return [ActivityUnavailableReason::PAST_DATE, $date, []];
        }

        foreach ($this->covered($activity, $date) as $day) {
            if ($closed = $this->closedReason($activity, $day)) {
                return [$closed[0], $day, ['closure_reason' => $closed[1], 'windows' => $this->windowsOn($activity, $day)]];
            }
        }

        if ($checkTime && $activity->operating_hours !== null) {
            if ($time === null) {
                return [ActivityUnavailableReason::TIME_REQUIRED, $date, ['windows' => $windows]];
            }

            $inside = collect($windows)->contains(fn (array $w) => $w['start'] <= $time && $time < $w['end']);

            if (! $inside) {
                return [ActivityUnavailableReason::OUTSIDE_OPENING_HOURS, $date, ['windows' => $windows]];
            }
        }

        $capacity = $activity->daily_capacity;

        if ($capacity === null) {
            return null;
        }

        if ($pax > $capacity) {
            return [ActivityUnavailableReason::PARTY_EXCEEDS_CAPACITY, $date, [
                'capacity' => $capacity, 'booked' => $load[$date] ?? 0, 'remaining' => $capacity - ($load[$date] ?? 0),
            ]];
        }

        foreach ($this->covered($activity, $date) as $day) {
            $booked = $load[$day] ?? 0;

            if ($booked + $pax > $capacity) {
                return [ActivityUnavailableReason::FULLY_BOOKED, $day, [
                    'capacity' => $capacity, 'booked' => $booked, 'remaining' => $capacity - $booked, 'windows' => $windows,
                ]];
            }
        }

        return null;
    }

    /**
     * Why the activity does not run on a date by its own rules (season,
     * closures, weekday), with the closure's reason when there is one.
     *
     * @return array{0: ActivityUnavailableReason, 1: ?string}|null
     */
    private function closedReason(Activity $activity, string $date): ?array
    {
        if (! $activity->is_active || $activity->trashed()) {
            return [ActivityUnavailableReason::INACTIVE, null];
        }

        if (($activity->available_from && $date < $activity->available_from->toDateString())
            || ($activity->available_until && $date > $activity->available_until->toDateString())) {
            return [ActivityUnavailableReason::OUT_OF_SEASON, null];
        }

        foreach ($activity->unavailable_periods ?? [] as $period) {
            if ($period['start_date'] <= $date && $date <= $period['end_date']) {
                return [ActivityUnavailableReason::CLOSURE_PERIOD, $period['reason'] ?? null];
            }
        }

        if ($activity->operating_hours !== null && $this->windowsOn($activity, $date) === []) {
            return [ActivityUnavailableReason::CLOSED_WEEKDAY, null];
        }

        return null;
    }

    /**
     * How many days one booking of the activity covers.
     */
    private function span(Activity $activity): int
    {
        return max(1, (int) ($activity->duration_days ?? 1));
    }

    /**
     * @return list<string>
     */
    private function dates(string $from, string $to): array
    {
        $dates = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private function hotel(Activity $activity): Hotel
    {
        return $activity->relationLoaded('hotel') && $activity->hotel
            ? $activity->hotel
            : Hotel::findOrFail($activity->hotel_id);
    }

    private function timezone(Hotel $hotel): string
    {
        return $hotel->timezone ?: 'UTC';
    }
}
