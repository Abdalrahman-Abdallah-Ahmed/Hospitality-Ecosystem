<?php

namespace App\Enums;

/**
 * The lines background work waits in, by how urgent it is (spec 017 FR-020).
 *
 * Each lane has its own workers, so a backlog in one never delays another: a
 * guest's WhatsApp reply never waits behind a night of insights or an import.
 * Jobs choose their lane here, never by connection, so moving the queue off
 * the database later changes configuration only (FR-021).
 */
enum QueueLane: string
{
    /** A guest is waiting on the other end: WhatsApp in and out, guest notices. */
    case REALTIME = 'realtime';

    /** Staff notifications and mail, and short scheduled jobs that are not AI work. */
    case DEFAULT = 'default';

    /** LLM and embedding work: insights, memories, recommendations, indexing. */
    case AI_BATCH = 'ai-batch';

    /** Reservation imports: heavy, and fine to run one at a time. */
    case IMPORTS = 'imports';

    /**
     * How long the oldest waiting job may wait before the platform owner is
     * alerted (spec 017 Clarifications, FR-028).
     */
    public function waitLimitSeconds(): int
    {
        return match ($this) {
            self::REALTIME => 60,
            self::DEFAULT => 300,
            self::AI_BATCH => 1800,
            self::IMPORTS => 3600,
        };
    }
}
