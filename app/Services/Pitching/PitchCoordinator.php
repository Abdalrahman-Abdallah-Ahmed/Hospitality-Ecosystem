<?php

namespace App\Services\Pitching;

use App\Enums\ActorKind;
use App\Enums\AttributionMethod;
use App\Enums\DeliveryChannel;
use App\Enums\OutcomeType;
use App\Enums\PitchGate;
use App\Enums\PitchResult;
use App\Enums\RecommendationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\RecommendationDeliveryService;
use App\Services\RecommendationOutcomeService;
use App\Support\Pitching\CandidateList;
use App\Support\Pitching\GateReport;
use App\Support\Pitching\GateResult;
use App\Support\Pitching\PitchTurn;
use App\Support\Pitching\TurnSignal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one guest turn's pitching decision and records it.
 *
 * Never throws. A failure anywhere here is reported and the turn becomes
 * ineligible: a missed pitch costs a small sale, while an unanswered guest or
 * a pitch to a complaining one costs far more.
 */
class PitchCoordinator
{
    public function __construct(
        private readonly PitchEligibilityService $eligibility,
        private readonly TurnSignalClassifier $classifier,
        private readonly PitchRecommendationGenerator $generator,
        private readonly RecommendationDeliveryService $delivery,
        private readonly RecommendationOutcomeService $outcomes,
    ) {}

    /**
     * Cheap gates → classifier → opening gates → candidates → decision row.
     *
     * Runs inside the job's AI cost context, so the classifier's spend is
     * filed against the guest message that caused it.
     */
    public function begin(Guest $guest, Hotel $hotel, ?Reservation $reservation, string $messageText, ?string $conversationId): PitchTurn
    {
        try {
            return $this->decide($guest, $hotel, $reservation?->stay, $messageText, $conversationId);
        } catch (Throwable $e) {
            report($e);

            return PitchTurn::ineligible();
        }
    }

    /**
     * The reply was generated. A turn that staged nothing ends here: either a
     * gate blocked it, or it was eligible and nothing was pitched. A staged
     * pitch stays open until the reply is actually sent (delivered()), or
     * the send gives up (abandon()). Never throws.
     */
    public function complete(PitchTurn $turn): void
    {
        if ($turn->staged()) {
            return;
        }

        try {
            $turn->decision?->update([
                'result' => $turn->eligible ? PitchResult::NO_PITCH : PitchResult::INELIGIBLE,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * WhatsApp accepted the reply: every pitch this guest's turn staged
     * since `$since` reached them. Read from the rows rather than the turn
     * object, because a send retried by a later attempt of the job no
     * longer has it (WP-17.3). Never throws.
     */
    public function delivered(Guest $guest, CarbonInterface $since, string $replyText): void
    {
        try {
            foreach ($this->openStaged($guest, $since) as $decision) {
                $this->markPitched($decision, $replyText, ActorKind::AI_AGENT);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The reply was never sent: the staged pitch did not reach the guest and
     * does not count toward any cap. Never throws.
     */
    public function abandon(Guest $guest, CarbonInterface $since): void
    {
        try {
            foreach ($this->openStaged($guest, $since) as $decision) {
                // Released, so it can be offered again and expires normally.
                Recommendation::withoutGlobalScope('hotel')
                    ->where('pitch_decision_id', $decision->id)
                    ->whereNull('delivered_at')
                    ->update(['pitch_decision_id' => null]);

                $decision->update(['result' => PitchResult::REPLY_FAILED, 'completed_at' => now()]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A staged pitch reached the guest: stamp delivery, record the
     * DELIVERED outcome, and complete the decision as pitched. Shared with
     * proactive pitches, which have no guest turn.
     */
    public function markPitched(PitchDecision $decision, string $sentText, ActorKind $actorKind): void
    {
        $recommendation = Recommendation::withoutGlobalScope('hotel')
            ->with('activity')
            ->where('pitch_decision_id', $decision->id)
            ->first();

        if ($recommendation) {
            $this->delivery->markDelivered($recommendation, DeliveryChannel::WHATSAPP, now(), $actorKind);

            $this->outcomes->record($recommendation->refresh(), OutcomeType::DELIVERED, AttributionMethod::CONVERSATIONAL, attributes: [
                'channel' => DeliveryChannel::WHATSAPP->value,
            ]);
        }

        $name = $recommendation?->activity?->name;

        $decision->update([
            'result' => PitchResult::PITCHED,
            'recommendation_id' => $recommendation?->id,
            // Stored, never used to block: the agent may reasonably
            // translate a name.
            'mention_verified' => $name !== null && Str::contains(Str::lower($sentText), Str::lower($name)),
            'completed_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, PitchDecision>
     */
    private function openStaged(Guest $guest, CarbonInterface $since): Collection
    {
        return PitchDecision::withoutGlobalScope('hotel')
            ->where('guest_id', $guest->id)
            ->whereNull('completed_at')
            ->where('decided_at', '>=', $since)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('recommendations')
                ->whereColumn('recommendations.pitch_decision_id', 'pitch_decisions.id'))
            ->get();
    }

    private function decide(Guest $guest, Hotel $hotel, ?Stay $stay, string $messageText, ?string $conversationId): PitchTurn
    {
        $now = now();
        $report = $this->eligibility->evaluateCheapGates($guest, $hotel, $stay, $now);
        $signal = null;
        $candidates = null;

        // The classifier costs money, so it runs only once every cheap gate
        // has passed. Once a guest is capped, it stops running for them.
        if ($report->passed()) {
            [$report, $signal] = $this->classify($guest, $hotel, $messageText, $report);
        }

        if ($report->passed()) {
            $report = $this->eligibility->evaluateOpeningGates($stay, $signal->opening, $report, $now);
        }

        if ($report->passed()) {
            // A reservation nobody has generated recommendations for has
            // nothing to offer. Generating here, once per reservation, puts
            // suggestions in front of an approver without staff having to
            // click the button; what it generates is pending approval, so
            // this turn shortlists only what was approved before it. A
            // failure here is not this turn's failure: it just leaves nothing
            // to shortlist, the same as if generation had never run.
            try {
                $this->generator->ensureGenerated($stay);
            } catch (Throwable $e) {
                report($e);
            }

            $candidates = $this->eligibility->candidates($hotel, $stay, $signal->interestCategoryId, $now);
            $report = $report->with($candidates->isEmpty()
                ? GateResult::block(PitchGate::NO_CANDIDATES, $this->noCandidatesDetail($stay))
                : GateResult::pass(PitchGate::NO_CANDIDATES));
        }

        $decision = $this->record($guest, $hotel, $stay, $conversationId, $report, $signal, $candidates);

        return new PitchTurn(
            $decision,
            $report->passed(),
            $candidates->shortlist ?? [],
            $messageText,
            isRetryTurn: $report->passed() && ! $signal->opening->isExplicitRequest() && $this->eligibility->pitchFlow($stay, $now)->isRetryNext(),
        );
    }

    /**
     * Why there is nothing to offer, telling apart "nothing approved yet"
     * from "nothing generated" (FR-016).
     */
    private function noCandidatesDetail(Stay $stay): string
    {
        $pending = Recommendation::where('reservation_id', $stay->reservation_id)
            ->where('status', RecommendationStatus::PENDING_APPROVAL->value)
            ->count();

        return $pending > 0 ? "Nothing approved; {$pending} pending approval." : 'No recommendation to offer.';
    }

    /**
     * @return array{0: GateReport, 1: ?TurnSignal}
     */
    private function classify(Guest $guest, Hotel $hotel, string $messageText, GateReport $report): array
    {
        try {
            $signal = $this->classifier->classify($guest, $hotel, $messageText);
        } catch (Throwable $e) {
            report($e);

            return [$report->with(GateResult::block(PitchGate::CLASSIFIER_FAILED, class_basename($e))), null];
        }

        return [$report->with(
            GateResult::pass(PitchGate::CLASSIFIER_FAILED),
            $signal->complaint
                ? GateResult::block(PitchGate::COMPLAINT_THIS_TURN)
                : GateResult::pass(PitchGate::COMPLAINT_THIS_TURN),
            $signal->opening
                ? GateResult::pass(PitchGate::NO_OPENING, $signal->opening->value)
                : GateResult::block(PitchGate::NO_OPENING),
        ), $signal];
    }

    private function record(
        Guest $guest,
        Hotel $hotel,
        ?Stay $stay,
        ?string $conversationId,
        GateReport $report,
        ?TurnSignal $signal,
        ?CandidateList $candidates,
    ): PitchDecision {
        return PitchDecision::create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'stay_id' => $stay?->id,
            'conversation_id' => $conversationId,
            'eligible' => $report->passed(),
            'gates' => $report->toArray(),
            'classifier_ran' => $signal !== null || $report->blocked(PitchGate::CLASSIFIER_FAILED),
            'complaint' => $signal?->complaint,
            'opening' => $signal?->opening,
            'explicit_request' => $signal?->opening?->isExplicitRequest(),
            'opening_quote' => $signal?->evidenceQuote ?: null,
            'interest_category_id' => $signal?->interestCategoryId,
            'candidates' => $candidates?->toArray(),
            'signals' => $stay ? $this->signals($guest, $stay) : null,
            'rules_version' => config('pitching.rules_version'),
            'decided_at' => now(),
        ]);
    }

    /**
     * The inputs this decision could draw on. Nationality and market segment
     * are recorded but never ranked on (pitching plan §15, D-6).
     *
     * @return array<string, mixed>
     */
    private function signals(Guest $guest, Stay $stay): array
    {
        return [
            'party' => ['adults' => $stay->adults, 'children' => $stay->children],
            'segment' => ['nationality' => $guest->nationality, 'market_segment' => $stay->market_segment],
        ];
    }
}
