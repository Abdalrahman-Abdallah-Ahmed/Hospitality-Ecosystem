<?php

namespace App\Services\Pitching;

use App\Enums\BookingStatus;
use App\Enums\CandidateExclusion;
use App\Enums\GuestSignal;
use App\Enums\PitchGate;
use App\Enums\PitchOpening;
use App\Enums\PitchResult;
use App\Enums\RecommendationStatus;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Stay;
use App\Models\Task;
use App\Support\Pitching\ActivityTimeframe;
use App\Support\Pitching\Candidate;
use App\Support\Pitching\CandidateList;
use App\Support\Pitching\GateReport;
use App\Support\Pitching\GateResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "May we pitch this guest right now?" — answered in code, before the agent
 * is built, so no prompt can talk its way past a rule.
 *
 * Every counter is derived from the rows that record the events, never
 * stored. "Today" is always the hotel's local date: a resort in UTC+3 is on
 * tomorrow's date from 21:00 UTC, exactly when guests ask about the evening.
 *
 * Callers run these inside TenantContext::runForHotel(); every query also
 * filters by hotel itself.
 */
class PitchEligibilityService
{
    /**
     * The gates that cost nothing but a query. All of them are evaluated even
     * after one fails — which rule does the blocking is itself worth knowing —
     * except those that need a stay when there is none.
     */
    public function evaluateCheapGates(Guest $guest, Hotel $hotel, ?Stay $stay, CarbonInterface $now): GateReport
    {
        $report = (new GateReport)->with(config('pitching.enabled')
            ? GateResult::pass(PitchGate::FEATURE_DISABLED)
            : GateResult::block(PitchGate::FEATURE_DISABLED, 'Pitching is switched off.'));

        if (! $stay) {
            return $report->with(GateResult::block(PitchGate::NO_STAY, 'The guest has no stay to pitch against.'));
        }

        $local = $this->local($hotel, $now);

        return $report->with(
            GateResult::pass(PitchGate::NO_STAY),
            $this->inHouseGate($stay),
            $this->departingGate($stay, $local),
            $this->escalationGate($guest, $hotel, $stay),
            $this->serviceRequestGate($guest, $hotel, $now),
        );
    }

    /**
     * The pitch cap and the one-refusal rule exist to stop the system
     * pushing. A guest who asks for a suggestion is not being pushed, so
     * neither applies to an explicit request.
     */
    public function evaluateOpeningGates(Stay $stay, PitchOpening $opening, GateReport $report): GateReport
    {
        if ($opening->isExplicitRequest()) {
            return $report->with(
                GateResult::pass(PitchGate::PITCH_CAP, 'Explicit request: the cap does not apply.'),
                GateResult::pass(PitchGate::DECLINED_THIS_STAY, 'Explicit request: an earlier refusal does not apply.'),
            );
        }

        $cap = (int) config('pitching.max_unsolicited_per_stay');
        $pitched = $this->unsolicitedPitchesThisStay($stay);

        // Read from the recommendation's status, not its outcome: the
        // confidence floor records a hesitant "no thanks" as DELIVERED, while
        // the status is REJECTED either way.
        $declined = Recommendation::where('reservation_id', $stay->reservation_id)
            ->where('status', RecommendationStatus::REJECTED)
            ->exists();

        return $report->with(
            $pitched >= $cap
                ? GateResult::block(PitchGate::PITCH_CAP, "{$pitched} unsolicited pitch(es) this stay; the cap is {$cap}.")
                : GateResult::pass(PitchGate::PITCH_CAP, "{$pitched} of {$cap} unsolicited pitch(es) used."),
            $declined
                ? GateResult::block(PitchGate::DECLINED_THIS_STAY, 'The guest refused a recommendation this stay.')
                : GateResult::pass(PitchGate::DECLINED_THIS_STAY),
        );
    }

    /**
     * Every active activity, minus the ones this guest cannot do in the days
     * they have left, each exclusion recorded with its reason. Ordered by
     * name until ranking (WP-17) orders it.
     */
    public function candidates(Hotel $hotel, Stay $stay, ?string $interestCategoryId, CarbonInterface $now): CandidateList
    {
        $local = $this->local($hotel, $now);
        $lastDay = $stay->planned_departure_date->copy()->subDay()->toDateString();
        $activities = Activity::active()->where('hotel_id', $hotel->id)->orderBy('name')->orderBy('id')->get();
        $stayBookings = $this->stayBookings($stay);
        $dailyPax = $this->dailyPax($hotel, $activities, $local, $lastDay);

        $shortlist = [];
        $excluded = [];

        foreach ($activities as $activity) {
            $result = $this->evaluateActivity($activity, $interestCategoryId, $stayBookings, $dailyPax, $local, $lastDay);

            if ($result instanceof Candidate) {
                $shortlist[] = $result;
            } else {
                $excluded[] = ['activity_id' => $activity->id, 'name' => $activity->name, ...$result];
            }
        }

        return new CandidateList(
            considered: $activities->count(),
            unscheduledBookings: $stayBookings->whereNull('scheduled_for')->count(),
            shortlist: array_slice($shortlist, 0, (int) config('pitching.shortlist_size')),
            excluded: $excluded,
        );
    }

    private function inHouseGate(Stay $stay): GateResult
    {
        $allowed = config('pitching.allow_pre_arrival')
            ? [StayStatus::IN_HOUSE, StayStatus::EXPECTED]
            : [StayStatus::IN_HOUSE];

        return in_array($stay->status, $allowed, true)
            ? GateResult::pass(PitchGate::NOT_IN_HOUSE)
            : GateResult::block(PitchGate::NOT_IN_HOUSE, "The stay is {$stay->status->value}.");
    }

    /**
     * On departure day the guest is still in-house but leaving within hours,
     * and pitching them is pointless and irritating.
     */
    private function departingGate(Stay $stay, CarbonInterface $local): GateResult
    {
        if ($stay->checked_out_at) {
            return GateResult::block(PitchGate::DEPARTING, 'The guest has checked out.');
        }

        $departure = $stay->planned_departure_date->toDateString();
        $today = $local->toDateString();

        return $departure <= $today
            ? GateResult::block(PitchGate::DEPARTING, "Departs {$departure}; today is {$today} at the hotel.")
            : GateResult::pass(PitchGate::DEPARTING);
    }

    private function escalationGate(Guest $guest, Hotel $hotel, Stay $stay): GateResult
    {
        if (! config('pitching.complaint.escalation_blocks_rest_of_stay')) {
            return GateResult::pass(PitchGate::ESCALATED_THIS_STAY, 'Escalations are configured not to block.');
        }

        $arrival = Carbon::parse($stay->planned_arrival_date->toDateString(), $this->timezone($hotel))->startOfDay();

        $escalated = Task::where('hotel_id', $hotel->id)
            ->where('guest_id', $guest->id)
            ->where('guest_signal', GuestSignal::ESCALATION->value)
            ->where('created_at', '>=', $arrival->utc())
            ->exists();

        return $escalated
            ? GateResult::block(PitchGate::ESCALATED_THIS_STAY, 'The guest was escalated to a human this stay.')
            : GateResult::pass(PitchGate::ESCALATED_THIS_STAY);
    }

    private function serviceRequestGate(Guest $guest, Hotel $hotel, CarbonInterface $now): GateResult
    {
        $hours = (int) config('pitching.complaint.open_service_request_lookback_hours');

        $open = Task::where('hotel_id', $hotel->id)
            ->where('guest_id', $guest->id)
            ->where('guest_signal', GuestSignal::SERVICE_REQUEST->value)
            ->whereIn('status', [TaskStatus::PENDING->value, TaskStatus::IN_PROGRESS->value])
            ->where('created_at', '>=', $now->copy()->subHours($hours))
            ->exists();

        return $open
            ? GateResult::block(PitchGate::OPEN_SERVICE_REQUEST, "A service request from the last {$hours}h is still open.")
            : GateResult::pass(PitchGate::OPEN_SERVICE_REQUEST);
    }

    /**
     * Counted from the decisions that produced pitches, never from a stored
     * counter that could drift. A staged pitch whose turn has not finished
     * counts, so two workers cannot both pitch; one whose reply failed never
     * reached the guest and does not.
     */
    private function unsolicitedPitchesThisStay(Stay $stay): int
    {
        return Recommendation::query()
            ->join('pitch_decisions', 'pitch_decisions.id', '=', 'recommendations.pitch_decision_id')
            ->where('pitch_decisions.stay_id', $stay->id)
            ->where('pitch_decisions.explicit_request', false)
            ->where(fn ($query) => $query
                ->whereNull('pitch_decisions.result')
                ->orWhere('pitch_decisions.result', PitchResult::PITCHED->value))
            ->count();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function stayBookings(Stay $stay): Collection
    {
        return Booking::where('stay_id', $stay->id)
            ->where('status', '!=', BookingStatus::CANCELLED->value)
            ->with('activity')
            ->get();
    }

    /**
     * People already booked per activity per local date, for the activities
     * whose capacity is known. Bookings with no date cannot fill a day.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, array<string, int>> activity id => date => pax
     */
    private function dailyPax(Hotel $hotel, Collection $activities, CarbonInterface $local, string $lastDay): array
    {
        $limited = $activities->whereNotNull('daily_capacity')->modelKeys();

        if ($limited === []) {
            return [];
        }

        $timezone = $this->timezone($hotel);
        $pax = [];

        Booking::where('hotel_id', $hotel->id)
            ->whereIn('activity_id', $limited)
            ->where('status', '!=', BookingStatus::CANCELLED->value)
            ->whereBetween('scheduled_for', [
                $local->copy()->startOfDay()->utc(),
                Carbon::parse($lastDay, $timezone)->endOfDay()->utc(),
            ])
            ->get(['activity_id', 'scheduled_for', 'pax'])
            ->each(function (Booking $booking) use (&$pax, $timezone) {
                $date = $booking->scheduled_for->copy()->setTimezone($timezone)->toDateString();
                $pax[$booking->activity_id][$date] = ($pax[$booking->activity_id][$date] ?? 0) + $booking->pax;
            });

        return $pax;
    }

    /**
     * The activity's possible start dates, or why it has none. The checks run
     * in a fixed order and each narrows the dates the next one sees: open,
     * then with room, then without a clash, then long enough.
     *
     * @param  Collection<int, Booking>  $stayBookings
     * @param  array<string, array<string, int>>  $dailyPax
     * @return Candidate|array{reason: string, detail: ?string}
     */
    private function evaluateActivity(
        Activity $activity,
        ?string $interestCategoryId,
        Collection $stayBookings,
        array $dailyPax,
        CarbonInterface $local,
        string $lastDay,
    ): Candidate|array {
        if ($interestCategoryId && $activity->category_id !== $interestCategoryId) {
            return $this->exclusion(CandidateExclusion::OUTSIDE_INTEREST);
        }

        if ($stayBookings->contains('activity_id', $activity->id)) {
            return $this->exclusion(CandidateExclusion::ALREADY_BOOKED);
        }

        $open = ActivityTimeframe::openDates($activity, $local, Carbon::parse($lastDay), $local);

        if ($open === []) {
            return $this->exclusion(CandidateExclusion::CLOSED_ON_ALL_DATES);
        }

        $withRoom = $activity->daily_capacity === null
            ? $open
            : array_values(array_filter($open, fn (string $date) => ($dailyPax[$activity->id][$date] ?? 0) < $activity->daily_capacity));

        if ($withRoom === []) {
            return $this->exclusion(CandidateExclusion::NO_CAPACITY, 'Full on all '.count($open).' open date(s).');
        }

        $free = array_values(array_diff($withRoom, $this->clashingDates($activity, $stayBookings, $local)));

        if ($free === []) {
            return $this->exclusion(CandidateExclusion::CLASHES_ON_ALL_DATES, 'The guest has a booking in the same category on every date it has room.');
        }

        $duration = $activity->duration_days ?? 1;
        $starts = ActivityTimeframe::startDates($free, $duration);

        if ($starts === []) {
            $longest = ActivityTimeframe::longestRun($free);

            return $this->exclusion(CandidateExclusion::NOT_ENOUGH_DAYS, "Needs {$duration} consecutive open days; the longest run is {$longest}.");
        }

        return new Candidate(
            activityId: $activity->id,
            name: $activity->name,
            openDates: $starts,
            capacityKnown: $activity->daily_capacity !== null,
        );
    }

    /**
     * Activities have hours but no length, so a true time clash cannot be
     * computed. Until they do (pitching plan §15, D-7): a date clashes when
     * the guest already holds a booking in the same category on it.
     *
     * @param  Collection<int, Booking>  $stayBookings
     * @return list<string>
     */
    private function clashingDates(Activity $activity, Collection $stayBookings, CarbonInterface $local): array
    {
        if ($activity->category_id === null) {
            return [];
        }

        return $stayBookings
            ->filter(fn (Booking $booking) => $booking->scheduled_for !== null
                && $booking->activity?->category_id === $activity->category_id)
            ->map(fn (Booking $booking) => $booking->scheduled_for->copy()->setTimezone($local->getTimezone())->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{reason: string, detail: ?string}
     */
    private function exclusion(CandidateExclusion $reason, ?string $detail = null): array
    {
        return ['reason' => $reason->value, 'detail' => $detail];
    }

    private function local(Hotel $hotel, CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->setTimezone($this->timezone($hotel));
    }

    private function timezone(Hotel $hotel): string
    {
        return $hotel->timezone ?: 'UTC';
    }
}
