<?php

namespace App\Jobs\Metering;

use App\Services\Metering\MeteringService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * The nightly safety net for usage counters.
 *
 * Counters are maintained incrementally as events are recorded, which is fast
 * but can drift — a crash between the two writes, or a counter someone edited
 * by hand. This recomputes every counter from the events that back it.
 *
 * Idempotent by construction: it overwrites with a computed total rather than
 * adjusting, so running it twice is the same as running it once.
 */
class RebuildUsageCountersJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?CarbonInterface $period = null,
    ) {}

    public function handle(MeteringService $metering): void
    {
        $metering->rebuild($this->period);
    }
}
