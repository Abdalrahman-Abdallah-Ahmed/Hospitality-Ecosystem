<?php

use App\Jobs\AiCost\FlagAiCostOverrunsJob;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Jobs\Metering\RebuildUsageCountersJob;
use App\Jobs\StartHousekeepingDayJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Infers recommendation -> booking links where nothing observed one directly.
// Runs after the usual night-audit window so the day's activity has settled.
Schedule::job(new MatchRecommendationOutcomesJob)->dailyAt('03:30');

// Rebuilds every usage counter from the meter events behind it. Counters are
// maintained incrementally as usage happens; this is the safety net that
// catches drift, and it logs any it finds rather than quietly correcting it.
Schedule::job(new RebuildUsageCountersJob)->dailyAt('04:00');

// Starts each hotel's housekeeping day: stay-over rooms become dirty with one
// cleaning task each. Hourly, so every time zone gets its own local date; the
// job runs once per hotel and date however often it fires.
Schedule::job(new StartHousekeepingDayJob)->hourly();

// Flags accounts whose AI cost has passed a configured share of what they
// pay. Alert only — it never throttles: a thin margin is a commercial
// conversation, not a decision for a cron job. Runs after the counter
// rebuild so the day's activity has settled.
Schedule::job(new FlagAiCostOverrunsJob)->dailyAt('04:30');
