<?php

namespace App\Enums;

/**
 * Where one guest's memory extraction for one reservation stands.
 */
enum GuestMemoryExtractionStatus: string
{
    case RUNNING = 'running';
    case PENDING_RETRY = 'pending_retry';
    case EXTRACTED = 'extracted';
    case SKIPPED_NO_MESSAGES = 'skipped_no_messages';
    case SKIPPED_DISABLED = 'skipped_disabled';
    case SKIPPED_SPEND_CEILING = 'skipped_spend_ceiling';
    case FAILED = 'failed';

    /**
     * A final result a super admin may re-run by hand (FR-036).
     */
    public function isRerunnable(): bool
    {
        return in_array($this, [self::FAILED, self::SKIPPED_SPEND_CEILING], true);
    }
}
