<?php

use App\Jobs\MatchRecommendationOutcomesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Infers recommendation -> booking links where nothing observed one directly.
// Runs after the usual night-audit window so the day's activity has settled.
Schedule::job(new MatchRecommendationOutcomesJob)->dailyAt('03:30');
