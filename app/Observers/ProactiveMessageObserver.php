<?php

namespace App\Observers;

use App\Enums\ProactiveMessageStatus;
use App\Models\Guest;
use App\Models\ProactiveMessage;
use App\Support\Ai\AiActivityRecorder;

/**
 * Every proactive message sent, or skipped and why, becomes a guest_outreach
 * entry (SPEC-083 R5). Sending uses no AI, so the entry belongs to no run.
 * The message body is never copied into it.
 */
class ProactiveMessageObserver
{
    private const ACTIONS = [
        ProactiveMessageStatus::SENT->value => 'proactive.sent',
        ProactiveMessageStatus::SKIPPED->value => 'proactive.skipped',
    ];

    public function created(ProactiveMessage $message): void
    {
        $this->record($message);
    }

    /**
     * Only a change of status counts: saving the same value again (or
     * changing something else) must not add a second entry.
     */
    public function updated(ProactiveMessage $message): void
    {
        if ($message->wasChanged('status')) {
            $this->record($message);
        }
    }

    private function record(ProactiveMessage $message): void
    {

        $action = self::ACTIONS[$message->status?->value] ?? null;

        if ($action === null) {
            return;
        }

        AiActivityRecorder::outreach(
            action: $action,
            target: $message,
            actedFor: Guest::withoutGlobalScope('hotel')->find($message->guest_id),
            tool: 'SendProactiveMessageJob',
            reason: $message->reason?->value,
            conversationId: $message->conversation_id,
        );
    }
}
