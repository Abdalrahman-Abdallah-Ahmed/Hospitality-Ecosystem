<?php

namespace App\Observers;

use App\Enums\OutcomeType;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use App\Support\Ai\AiActivityRecorder;

/**
 * How a guest answered a pitch becomes a guest_outreach entry (SPEC-083 R5).
 * Only outcomes of recommendations the Concierge actually pitched count;
 * an outcome recorded at the desk for an unpitched one is not AI outreach.
 */
class RecommendationOutcomeObserver
{
    private const ACTIONS = [
        OutcomeType::ACCEPTED->value => 'pitch.accepted',
        OutcomeType::BOOKED->value => 'pitch.accepted',
        OutcomeType::DECLINED->value => 'pitch.declined',
        OutcomeType::EXPIRED->value => 'pitch.no_answer',
    ];

    public function created(RecommendationOutcome $outcome): void
    {
        $this->record($outcome);
    }

    /**
     * Only a change of outcome counts: saving the same value again (or
     * changing something else) must not add a second entry.
     */
    public function updated(RecommendationOutcome $outcome): void
    {
        if ($outcome->wasChanged('outcome')) {
            $this->record($outcome);
        }
    }

    private function record(RecommendationOutcome $outcome): void
    {

        $action = self::ACTIONS[$outcome->outcome?->value] ?? null;

        if ($action === null) {
            return;
        }

        // `pitch.accepted` once, whether the guest said yes and then booked
        // or booked straight away.
        if ($outcome->outcome === OutcomeType::BOOKED && $outcome->getOriginal('outcome') === OutcomeType::ACCEPTED) {
            return;
        }

        $recommendation = Recommendation::withoutGlobalScope('hotel')->with('reservation.guest')->find($outcome->recommendation_id);

        if ($recommendation?->pitch_decision_id === null) {
            return;
        }

        AiActivityRecorder::outreach(
            action: $action,
            target: $recommendation,
            actedFor: $recommendation->reservation?->guest,
            tool: 'UpdateRecommendationTool',
        );
    }
}
