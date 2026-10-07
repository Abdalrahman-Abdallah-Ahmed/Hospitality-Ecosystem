<?php

namespace App\Enums;

/**
 * Whether the guest was told how their request ended (SPEC-044). Null on the
 * task means not processed yet; `pending` is the job's claim on it.
 */
enum GuestNoticeStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
}
