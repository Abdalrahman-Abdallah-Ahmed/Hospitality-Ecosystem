<?php

use App\Notifications\PlatformAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Spec 017 (FR-028, research R9): queue:check-waits alerts the platform owner
| once per lane per cooldown when that lane's oldest waiting job is past the
| lane's limit (realtime 60 s, default 300 s, ai-batch 1800 s, imports 3600 s).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key', 'ops.alert_email' => 'ops@example.test']);
    Notification::fake();
});

function waitingJob(string $queue, int $secondsAgo, bool $reserved = false): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reserved ? now()->getTimestamp() : null,
        'available_at' => now()->getTimestamp() - $secondsAgo,
        'created_at' => now()->getTimestamp() - $secondsAgo,
    ]);
}

/**
 * @return list<string>
 */
function laneAlertSubjects(): array
{
    return Notification::sent(new AnonymousNotifiable, PlatformAlertNotification::class)
        ->map(fn (PlatformAlertNotification $notification) => $notification->subject)
        ->values()
        ->all();
}

it('alerts once when a guest message waits past a minute', function () {
    waitingJob('realtime', 61);

    $this->artisan('queue:check-waits')->assertSuccessful();
    $this->artisan('queue:check-waits')->assertSuccessful();

    Notification::assertSentOnDemand(
        PlatformAlertNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.test',
    );
    expect(laneAlertSubjects())->toHaveCount(1)
        ->and(laneAlertSubjects()[0])->toStartWith('[Ops] Lane realtime waiting');
});

it('judges each lane by its own limit', function () {
    waitingJob('imports', 61);
    waitingJob('ai-batch', 1700);
    waitingJob('default', 299);

    $this->artisan('queue:check-waits')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignores a job a worker already holds', function () {
    waitingJob('realtime', 600, reserved: true);

    $this->artisan('queue:check-waits')->assertSuccessful();

    Notification::assertNothingSent();
});

it('alerts for every lane that is late, each on its own', function () {
    waitingJob('realtime', 120);
    waitingJob('default', 400);

    $this->artisan('queue:check-waits')->assertSuccessful();

    expect(laneAlertSubjects())->toHaveCount(2);
});

it('alerts again for a lane still late after the cooldown', function () {
    waitingJob('realtime', 120);

    $this->artisan('queue:check-waits')->assertSuccessful();
    $this->travel(16)->minutes();
    $this->artisan('queue:check-waits')->assertSuccessful();

    expect(laneAlertSubjects())->toHaveCount(2);
});
