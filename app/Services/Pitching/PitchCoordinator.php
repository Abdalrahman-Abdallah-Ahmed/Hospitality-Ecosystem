<?php

namespace App\Services\Pitching;

use App\Enums\PitchGate;
use App\Enums\PitchResult;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\Reservation;
use App\Models\Stay;
use App\Support\Pitching\CandidateList;
use App\Support\Pitching\GateReport;
use App\Support\Pitching\GateResult;
use App\Support\Pitching\PitchTurn;
use App\Support\Pitching\TurnSignal;
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
     * The turn ended without a pitch: either a gate blocked it, or it was
     * eligible and nothing was pitched. Never throws.
     */
    public function complete(PitchTurn $turn): void
    {
        try {
            $turn->decision?->update([
                'result' => $turn->eligible ? PitchResult::NO_PITCH : PitchResult::INELIGIBLE,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
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
            $report = $this->eligibility->evaluateOpeningGates($stay, $signal->opening, $report);
        }

        if ($report->passed()) {
            $candidates = $this->eligibility->candidates($hotel, $stay, $signal->interestCategoryId, $now);
            $report = $report->with($candidates->isEmpty()
                ? GateResult::block(PitchGate::NO_CANDIDATES, 'No activity survived the exclusions.')
                : GateResult::pass(PitchGate::NO_CANDIDATES));
        }

        $decision = $this->record($guest, $hotel, $stay, $conversationId, $report, $signal, $candidates);

        return new PitchTurn($decision, $report->passed(), $candidates->shortlist ?? []);
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
