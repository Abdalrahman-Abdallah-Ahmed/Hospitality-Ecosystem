<?php

namespace App\Services\Pitching;

use App\Enums\GuestSignal;
use App\Enums\PitchGate;
use App\Enums\PitchOpening;
use App\Enums\PitchResult;
use App\Enums\RecommendationStatus;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Stay;
use App\Models\Task;
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
     * The only reason a candidate is excluded. A single value, not an enum:
     * offering a category the guest did not ask about is the one thing
     * pitching still refuses to do on its own judgment (16.3).
     */
    private const OUTSIDE_INTEREST = 'outside_interest';

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
     * The reservation's pending recommendations, in the order
     * RecommendationAgent set. Pitching forms no opinion of its own about
     * which activity suits this guest, or whether it still fits their
     * remaining days: that judgment already happened when they were
     * generated. The only filter is the guest's own words — offering a
     * category they did not ask about is not "whatever was generated", it is
     * answering a different question.
     */
    public function candidates(Hotel $hotel, Stay $stay, ?string $interestCategoryId, CarbonInterface $now): CandidateList
    {
        $recommendations = $this->pendingRecommendations($stay);
        $shortlist = [];
        $excluded = [];

        foreach ($recommendations as $recommendation) {
            $activity = $recommendation->activity;

            if ($interestCategoryId && $activity->category_id !== $interestCategoryId) {
                $excluded[] = [
                    'recommendation_id' => $recommendation->id,
                    'activity_id' => $activity->id,
                    'name' => $activity->name,
                    'reason' => self::OUTSIDE_INTEREST,
                    'detail' => null,
                ];

                continue;
            }

            $shortlist[] = new Candidate(
                recommendationId: $recommendation->id,
                activityId: $activity->id,
                name: $activity->name,
                reason: $recommendation->reason,
                priority: (int) $recommendation->priority,
                predictedConfidence: $recommendation->predicted_confidence,
            );
        }

        return new CandidateList(
            considered: $recommendations->count(),
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
     * Still offerable: generated, never delivered, never pitched, and its
     * activity still on the menu.
     *
     * @return Collection<int, Recommendation>
     */
    private function pendingRecommendations(Stay $stay): Collection
    {
        return Recommendation::query()
            ->where('reservation_id', $stay->reservation_id)
            ->where('status', RecommendationStatus::PENDING->value)
            ->whereNull('delivered_at')
            ->whereNull('pitch_decision_id')
            ->whereHas('activity', fn ($query) => $query->where('is_active', true))
            ->with('activity')
            // The recommendation agent's own order: its top suggestion first.
            ->orderBy('priority')
            ->orderByDesc('predicted_confidence')
            ->orderBy('id')
            ->get();
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
