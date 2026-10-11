<?php

namespace App\Enums;

/**
 * The agent an AI activity entry belongs to — and, as the AI capability a
 * provider call is reported under, the shared key between the usage and cost
 * reports (SPEC-091).
 */
enum AiAgentName: string
{
    case CONCIERGE = 'concierge';
    case ADMIN_ADVISOR = 'admin_advisor';
    case RECOMMENDATION = 'recommendation';
    case INSIGHTS = 'insights';
    case TURN_SIGNAL = 'turn_signal';
    case DOCUMENT_VISION = 'document_vision';
    case GUEST_MEMORY = 'guest_memory';
    case KNOWLEDGE = 'knowledge';       // embedding-only runs (knowledge sync)
    case UNATTRIBUTED = 'unattributed'; // the caller named no agent; visible, never guessed

    public function label(): string
    {
        return match ($this) {
            self::CONCIERGE => 'Concierge',
            self::ADMIN_ADVISOR => 'Admin AI',
            self::RECOMMENDATION => 'Recommendations',
            self::INSIGHTS => 'Insights',
            self::TURN_SIGNAL => 'Turn classification',
            self::DOCUMENT_VISION => 'Document reading',
            self::GUEST_MEMORY => 'Guest memories',
            self::KNOWLEDGE => 'Knowledge indexing',
            self::UNATTRIBUTED => 'Unattributed',
        };
    }

    /**
     * The capabilities a usage report lists, always, even at zero.
     *
     * Turn classification is left out: it always runs inside a Concierge
     * turn, so its calls are reported under the Concierge and a row of its
     * own would read zero forever. Unattributed is left out because it is a
     * gap, shown only when it is not empty.
     *
     * @return array<int, self>
     */
    public static function reportable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $agent) => ! in_array($agent, [self::TURN_SIGNAL, self::UNATTRIBUTED], true),
        ));
    }
}
