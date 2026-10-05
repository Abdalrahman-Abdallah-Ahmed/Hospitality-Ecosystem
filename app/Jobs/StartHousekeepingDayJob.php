<?php

namespace App\Jobs;

use App\Models\Hotel;
use App\Services\HousekeepingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts each hotel's housekeeping day (SPEC-030, FR-012): every room with a
 * guest staying on becomes dirty and gets one stay-over cleaning task.
 *
 * Runs hourly so every hotel gets its own local date within an hour of
 * midnight, whatever its time zone. HousekeepingService::startDay() records
 * the run per hotel and date first, so the other 23 runs of the day, a retry
 * or a concurrent worker find it and do nothing. A day missed entirely is
 * not backfilled: yesterday's stay-over clean is no longer useful.
 *
 * Each hotel runs in its own tenant context and transaction, so one hotel's
 * failure is reported and the others still run.
 */
class StartHousekeepingDayJob implements ShouldQueue
{
    use Queueable;

    public function handle(HousekeepingService $housekeeping): void
    {
        Hotel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($hotels) use ($housekeeping): void {
                foreach ($hotels as $hotel) {
                    $day = CarbonImmutable::now($hotel->timezone)->toDateString();

                    try {
                        TenantContext::runForHotel($hotel->id, fn () => $housekeeping->startDay($hotel, $day));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }
}
