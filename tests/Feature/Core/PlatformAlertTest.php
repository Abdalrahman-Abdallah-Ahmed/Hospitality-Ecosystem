<?php

use App\Listeners\AlertOnRepeatedJobFailures;
use App\Notifications\PlatformAlertNotification;
use App\Support\Observability\PlatformAlerts;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/*
| Spec 017 (FR-028, research R14): a job class failing three times within 15
| minutes sends one alert; each source is deduplicated on its own, so one
| noisy job never hides another; no alert carries a job payload.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key', 'ops.alert_email' => 'ops@example.test']);
    Notification::fake();
});

function failJob(string $class, string $payloadSecret = 'guest +201001234567 asked for towels'): void
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($class);
    $job->shouldReceive('getQueue')->andReturn('realtime');
    $job->shouldReceive('payload')->andReturn(['data' => $payloadSecret]);
    $job->shouldReceive('getRawBody')->andReturn($payloadSecret);

    event(new JobFailed('database', $job, new RuntimeException('Provider timed out')));
}

/**
 * @return Collection<int, PlatformAlertNotification>
 */
function sentAlerts()
{
    return Notification::sent(new AnonymousNotifiable, PlatformAlertNotification::class);
}

it('alerts once when one job class fails three times within 15 minutes', function () {
    failJob('App\Jobs\SendWhatsAppMessageJob');
    failJob('App\Jobs\SendWhatsAppMessageJob');

    expect(sentAlerts())->toHaveCount(0);

    failJob('App\Jobs\SendWhatsAppMessageJob');
    failJob('App\Jobs\SendWhatsAppMessageJob');

    expect(sentAlerts())->toHaveCount(1)
        ->and(sentAlerts()->first()->subject)->toBe('[Ops] Job failing repeatedly: App\Jobs\SendWhatsAppMessageJob');
});

it('keeps counting each job class on its own', function () {
    foreach (range(1, 3) as $i) {
        failJob('App\Jobs\SendWhatsAppMessageJob');
        failJob('App\Jobs\CreateAiInsightsJob');
    }

    expect(sentAlerts()->pluck('subject')->sort()->values()->all())->toBe([
        '[Ops] Job failing repeatedly: App\Jobs\CreateAiInsightsJob',
        '[Ops] Job failing repeatedly: App\Jobs\SendWhatsAppMessageJob',
    ]);
});

it('starts counting again after the window', function () {
    failJob('App\Jobs\SendWhatsAppMessageJob');
    failJob('App\Jobs\SendWhatsAppMessageJob');
    $this->travel(16)->minutes();
    failJob('App\Jobs\SendWhatsAppMessageJob');

    expect(sentAlerts())->toHaveCount(0);
});

it('never puts the job payload in the alert', function () {
    foreach (range(1, 3) as $i) {
        failJob('App\Jobs\SendWhatsAppMessageJob');
    }

    $mail = sentAlerts()->first()->toMail(new AnonymousNotifiable);

    expect(json_encode($mail->toArray()))->not->toContain('201001234567')
        ->not->toContain('towels');
});

it('only logs an alert when no address is configured', function () {
    config(['ops.alert_email' => null]);

    expect(PlatformAlerts::send('alert:test', 15, 'Subject', ['Line']))->toBeFalse();
    Notification::assertNothingSent();
});

it('handles failures through the discovered listener exactly once', function () {
    expect(collect(app('events')->getListeners(JobFailed::class)))->toHaveCount(1);
    expect(class_exists(AlertOnRepeatedJobFailures::class))->toBeTrue();
});

it('does not silence a source while no address is configured', function () {
    config(['ops.alert_email' => null]);
    PlatformAlerts::send('alert:test', 15, 'Subject', ['Line']);

    config(['ops.alert_email' => 'ops@example.test']);

    expect(PlatformAlerts::send('alert:test', 15, 'Subject', ['Line']))->toBeTrue();
});

it('never throws when the mail fails, and tries again on the next alert', function () {
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

    expect(PlatformAlerts::send('alert:test', 15, 'Subject', ['Line']))->toBeFalse();

    Notification::fake();

    expect(PlatformAlerts::send('alert:test', 15, 'Subject', ['Line']))->toBeTrue();
});
