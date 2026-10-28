<?php

namespace App\Enums;

/**
 * Where a voucher email request is (research R9): queued → sending → sent,
 * or skipped when the reservation stopped being eligible, or failed after
 * the job's last try.
 */
enum VoucherEmailStatus: string
{
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case SENT = 'sent';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT, self::SKIPPED, self::FAILED], true);
    }
}
