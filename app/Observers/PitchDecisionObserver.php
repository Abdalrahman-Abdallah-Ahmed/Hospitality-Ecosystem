<?php

namespace App\Observers;

use App\Enums\PitchResult;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Support\Ai\AiActivityRecorder;

/**
 * A pitch the guest actually received becomes a guest_outreach entry in the
 * AI activity list (SPEC-083 R5). The decision reaches `pitched` exactly
 * once, when the reply carrying the offer was sent.
 */
class PitchDecisionObserver
{
    public function created(PitchDecision $decision): void
    {
        $this->record($decision);
    }

    /**
     * Only a change of result counts: saving the same value again (or
     * changing something else) must not add a second entry.
     */
    public function updated(PitchDecision $decision): void
    {
        if ($decision->wasChanged('result')) {
            $this->record($decision);
        }
    }

    private function record(PitchDecision $decision): void
    {

        if ($decision->result !== PitchResult::PITCHED || $decision->recommendation_id === null) {
            return;
        }

        $recommendation = Recommendation::withoutGlobalScope('hotel')->find($decision->recommendation_id);

        if ($recommendation === null) {
            return;
        }

        AiActivityRecorder::outreach(
            action: 'pitch.offered',
            target: $recommendation,
            actedFor: $decision->guest,
            tool: $decision->proactive_message_id ? 'SendProactiveMessageJob' : 'PitchActivityTool',
            conversationId: $decision->conversation_id,
        );
    }
}
