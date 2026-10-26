<?php

namespace App\Listeners;

use App\Support\Observability\PlatformAlerts;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the platform owner when one kind of job keeps failing: three
 * failures of the same job class within 15 minutes send one alert, at most
 * once per class per 15 minutes (spec 017 FR-028, research R14).
 *
 * Counted per class, so one noisy job never hides another. The mail names
 * the class and the queue only; the payload carries guest data and stays
 * out of it.
 */
class AlertOnRepeatedJobFailures
{
    public function handle(JobFailed $event): void
    {
        $class = $event->job->resolveName();
        $window = (int) config('ops.job_failure_window_minutes');
        $threshold = (int) config('ops.job_failure_threshold');
        $counter = "alert:job-failures:{$class}";

        Cache::add($counter, 0, now()->addMinutes($window));
        $failures = (int) Cache::increment($counter);

        if ($failures < $threshold) {
            return;
        }

        PlatformAlerts::send("alert:job-failed:{$class}", $window, "[Ops] Job failing repeatedly: {$class}", [
            "{$class} has failed {$failures} times in the last {$window} minutes.",
            'Queue: '.$event->job->getQueue().'.',
            'Last failure: '.now()->toDateTimeString().' ('.class_basename($event->exception).').',
            'Details are in Sentry and in the failed_jobs table.',
        ]);
    }
}
