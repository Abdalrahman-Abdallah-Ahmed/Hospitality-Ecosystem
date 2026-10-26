<?php

namespace App\Jobs;

use App\Enums\GuestMemoryExtractionStatus;
use App\Enums\QueueLane;
use App\Models\GuestMemoryExtraction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives each guest memory extraction that failed, or was stopped by the
 * spend ceiling, its one retry once it is due (SPEC-084 FR-036). The job
 * claims the row itself, so dispatching one that is no longer due is
 * harmless.
 */
class RetryGuestMemoryExtractionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // LLM or embedding work: it must never hold up a guest reply (spec 017 FR-020).
        $this->onQueue(QueueLane::AI_BATCH->value);
    }

    public function handle(): void
    {
        // Every hotel's due retries, deliberately: a scheduler run has no
        // tenant, and each extraction scopes itself to its own hotel.
        GuestMemoryExtraction::withoutGlobalScope('hotel')
            ->where('status', GuestMemoryExtractionStatus::PENDING_RETRY->value)
            ->where('retry_at', '<=', now())
            ->orderBy('retry_at')
            ->get(['hotel_id', 'guest_id', 'reservation_id'])
            ->each(fn (GuestMemoryExtraction $extraction) => ExtractGuestMemoriesJob::dispatch(
                $extraction->hotel_id,
                $extraction->guest_id,
                $extraction->reservation_id,
            ));
    }
}
