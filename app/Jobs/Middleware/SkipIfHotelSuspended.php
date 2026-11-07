<?php

namespace App\Jobs\Middleware;

use App\Contracts\HasHotel;
use App\Exceptions\HotelSuspended;
use App\Models\Hotel;
use App\Support\Observability\TagsSentryScope;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A suspended hotel's job doesn't run. Not calling $next lets the queue delete
 * the job as finished, so it writes no failed_jobs row and sets off no alert.
 * Its failed() handler still runs, so a record it left in progress ends failed.
 *
 * The check runs only when a job starts: one already running finishes. Skipped
 * work is not replayed.
 */
class SkipIfHotelSuspended
{
    public function handle(HasHotel $job, Closure $next): void
    {
        $hotelId = $job->hotelId();
        $hotel = $hotelId === null ? null : Hotel::withTrashed()->withSuspension()->find($hotelId);

        if (! $hotel?->isSuspended()) {
            $next($job);

            return;
        }

        TagsSentryScope::tag(['suspended' => 'true']);

        // A handler that throws here would fail the job after all, and bring
        // back the failed_jobs row and the alert.
        if (method_exists($job, 'failed')) {
            try {
                $job->failed(new HotelSuspended($hotelId));
            } catch (Throwable $e) {
                report($e);
            }
        }

        Log::info('job.skipped_suspended', ['job' => $job::class, 'hotel_id' => $hotelId]);
    }
}
