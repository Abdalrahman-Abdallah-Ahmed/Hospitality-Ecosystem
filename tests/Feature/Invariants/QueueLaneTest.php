<?php

use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Support\EntryPointDiscovery;

/*
| Spec 017 (FR-020, FR-022, research R9): every job names its lane, every lane
| has a worker on the server, and no job can outlive the queue's retry_after
| (a job still running then is handed to a second worker).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * The lane a class sets with $this->onQueue(QueueLane::X->value), read from
 * its source: most job constructors need real records to run.
 */
function queueLaneOf(string $class): ?QueueLane
{
    $source = file_get_contents((new ReflectionClass($class))->getFileName());

    if (! preg_match('/\$this->onQueue\(QueueLane::([A-Z_]+)->value\)/', $source, $match)) {
        return null;
    }

    return constant(QueueLane::class.'::'.$match[1]);
}

/**
 * Lanes with a worker instance listed in deploy/systemd/README.md.
 *
 * @return list<string>
 */
function lanesWithWorkers(): array
{
    preg_match_all('/queue-worker@([a-z-]+)-\d+/', file_get_contents(base_path('deploy/systemd/README.md')), $matches);

    return array_values(array_unique($matches[1]));
}

it('puts every queued job in a lane', function () {
    foreach (EntryPointDiscovery::queuedJobs() as $class) {
        expect(queueLaneOf($class))->toBeInstanceOf(QueueLane::class, "{$class} does not choose a QueueLane in its constructor");
    }
});

it('leaves every queued notification on the default lane', function () {
    foreach (Finder::create()->files()->in(app_path('Notifications'))->name('*.php') as $file) {
        $class = 'App\\Notifications\\'.$file->getFilenameWithoutExtension();

        if (is_subclass_of($class, ShouldQueue::class)) {
            expect(queueLaneOf($class) ?? QueueLane::DEFAULT)->toBe(QueueLane::DEFAULT, "{$class} leaves the default lane");
        }
    }
});

it('runs a worker for every lane, so no job waits on a lane nobody listens to', function () {
    $workers = lanesWithWorkers();

    foreach (QueueLane::cases() as $lane) {
        expect(in_array($lane->value, $workers, true))->toBeTrue("No queue-worker@{$lane->value}-N instance in deploy/systemd/README.md");
    }
});

it('keeps every job timeout below the queue retry_after', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    foreach (EntryPointDiscovery::queuedJobs() as $class) {
        // A job without its own $timeout gets the worker's --timeout=90.
        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 90;

        expect($timeout ?? 90)->toBeLessThan($retryAfter, "{$class} may run past retry_after and be run twice");
    }
});

it('gives every lane a wait limit', function () {
    foreach (QueueLane::cases() as $lane) {
        expect($lane->waitLimitSeconds())->toBeGreaterThan(0);
    }

    expect(QueueLane::REALTIME->waitLimitSeconds())->toBe(60)
        ->and(QueueLane::DEFAULT->waitLimitSeconds())->toBe(300)
        ->and(QueueLane::AI_BATCH->waitLimitSeconds())->toBe(1800)
        ->and(QueueLane::IMPORTS->waitLimitSeconds())->toBe(3600);
});

it('escapes the lane expansion so systemd hands it to bash intact', function () {
    // systemd expands "$" and "%" itself: a single "$" would be replaced with
    // nothing and every worker would read the default queue.
    $unit = file_get_contents(base_path('deploy/systemd/queue-worker@.service'));

    expect($unit)->toContain('--queue="$${0%%-*}"');
});
