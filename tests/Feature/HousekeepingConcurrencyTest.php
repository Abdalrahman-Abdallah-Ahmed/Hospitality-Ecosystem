<?php

use App\Enums\CleaningReason;
use App\Enums\HousekeepingCause;
use App\Enums\Priority;
use App\Models\EventLog;
use App\Models\HousekeepingDayRun;
use App\Models\Room;
use App\Models\Task;
use App\Services\HousekeepingService;
use App\Services\MaintenanceService;
use App\Services\StayLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
| Two people acting on the same room at the same moment have one effect
| (FR-007, FR-013, FR-025, FR-035, SC-003). A second connection holds the
| room's row lock, which needs committed rows, so this file uses
| DatabaseTruncation like CheckInOutConcurrencyTest.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    config(['database.connections.pgsql_b' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    $b = DB::connection('pgsql_b');

    while ($b->transactionLevel() > 0) {
        $b->rollBack();
    }

    DB::purge('pgsql_b');
    $this->truncateTablesForAllConnections();
});

/**
 * Runs `$action` while connection B holds the room's row lock, and proves it
 * waits for it rather than reading around it.
 */
function assertWaitsForRoomLock($test, string $roomId, callable $action): void
{
    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->select('select id from rooms where id = ? for update', [$roomId]);

    try {
        DB::transaction(function () use ($action) {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            $action();
        });
        $test->fail('The action should have waited for the room lock.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('55P03');
    }

    $b->commit();
}

it('makes a second start of the same clean wait, then change nothing', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    $housekeeping = app(HousekeepingService::class);
    $start = function () use ($task, $housekeeping) {
        $before = $task->fresh()->getAttributes();
        $fresh = $task->fresh();
        $fresh->update(['status' => 'in_progress']);
        $housekeeping->taskChanged($fresh, $before);
    };

    assertWaitsForRoomLock($this, $room->id, fn () => $housekeeping->taskChanged($task->fresh(), $task->fresh()->getAttributes()));

    $start();
    $start();

    expect(EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->get()
        ->filter(fn ($log) => ($log->changes['housekeeping_status']['to'] ?? null) === 'cleaning'))->toHaveCount(1);
});

it('serializes a check-out and taking the same room out of order', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    app(StayLifecycleService::class)->checkIn($stay);
    $maintenance = app(MaintenanceService::class);

    assertWaitsForRoomLock($this, $room->id, fn () => $maintenance->takeOutOfOrder($room, $hotel->owner, 'Leak'));

    app(StayLifecycleService::class)->checkOut($stay->fresh());
    $maintenance->takeOutOfOrder($room, $hotel->owner, 'Leak');

    expect(Room::withoutGlobalScope('hotel')->find($room->id)->status->value)->toBe('out_of_order')
        ->and(DB::table('stays')->where('room_id', $room->id)->where('status', 'in_house')->count())->toBe(0);
});

it('runs start of day once for a hotel and date, even run twice', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $this->travelTo(now()->subDay()->startOfDay()->addHours(14));
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id], ['arrival_date' => now()->toDateString(), 'departure_date' => now()->addDays(3)->toDateString()]));
    app(StayLifecycleService::class)->checkIn($stay);
    $this->travelBack();
    $housekeeping = app(HousekeepingService::class);

    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->table('housekeeping_day_runs')->insert(['id' => (string) str()->uuid(), 'hotel_id' => $hotel->id, 'day' => now()->toDateString(), 'created_at' => now()]);

    $first = null;
    try {
        DB::transaction(function () use ($housekeeping, $hotel, &$first) {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            $first = $housekeeping->startDay($hotel, now()->toDateString());
        });
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('55P03');
    }
    $b->rollBack();

    $housekeeping->startDay($hotel, now()->toDateString());
    $housekeeping->startDay($hotel, now()->toDateString());

    expect(HousekeepingDayRun::where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and(Task::withoutGlobalScope('hotel')->where('room_id', $room->id)->count())->toBe(1);
});

it('keeps one maintenance task when the same issue is reported twice', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    $maintenance = app(MaintenanceService::class);

    assertWaitsForRoomLock($this, $room->id, fn () => $maintenance->reportIssue($cleaning, $hotel->owner, 'Leak', Priority::NORMAL, false));

    $maintenance->reportIssue($cleaning, $hotel->owner, 'Leak', Priority::NORMAL, false);
    $maintenance->reportIssue($cleaning, $hotel->owner, 'Leak', Priority::NORMAL, false);

    expect(Task::withoutGlobalScope('hotel')->where('source_task_id', $cleaning->id)->count())->toBe(1);
});

it('never holds two open cleaning tasks for one room, even written around the service', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);

    app(HousekeepingService::class)->roomNeedsCleaning($room, CleaningReason::MANUAL, HousekeepingCause::MANUAL);

    expect(fn () => DB::table('tasks')->insert([
        'id' => (string) str()->uuid(), 'hotel_id' => $hotel->id, 'room_id' => $room->id, 'title' => 'Duplicate',
        'status' => 'pending', 'housekeeping_kind' => 'cleaning', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(Task::withoutGlobalScope('hotel')->where('room_id', $room->id)->open()->count())->toBe(1);
});
