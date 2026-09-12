<?php

use App\Jobs\AiCost\FlagAiCostOverrunsJob;
use App\Jobs\MakeRoomDirtyOvernightJob;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Jobs\Metering\RebuildUsageCountersJob;
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

Schedule::job(new MakeRoomDirtyOvernightJob)->dailyAt('00:01');

// Flags accounts whose AI cost has passed a configured share of what they
// pay. Alert only — it never throttles: a thin margin is a commercial
// conversation, not a decision for a cron job. Runs after the counter
// rebuild so the day's activity has settled.
Schedule::job(new FlagAiCostOverrunsJob)->dailyAt('04:30');
