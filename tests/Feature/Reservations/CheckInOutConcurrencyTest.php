<?php

use App\Enums\StayStatus;
use App\Models\EventLog;
use App\Models\Task;
use App\Services\StayLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Two desks acting on the same room at the same moment must have one effect
| (FR-010, FR-014, SC-006). A second connection holds the lock the service
| takes, which needs committed rows, so this file uses DatabaseTruncation
| (like ReservationAvailabilityConcurrencyTest) and truncates afterwards.
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
 * Runs `$action` while connection B holds the reservation's row lock, and
 * proves it waits for it rather than reading around it.
 */
function assertWaitsForReservationLock($test, string $reservationId, callable $action): void
{
    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->select('select id from reservations where id = ? for update', [$reservationId]);

    try {
        DB::transaction(function () use ($action) {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            $action();
        });
        $test->fail('The action should have waited for the reservation lock.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('55P03');
    }

    $b->commit();
}

it('makes a second check-in of the same room wait, then find it done', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $lifecycle = app(StayLifecycleService::class);

    assertWaitsForReservationLock($this, $reservation->id, fn () => $lifecycle->checkIn($stay));

    $lifecycle->checkIn($stay);
    $lifecycle->checkIn($stay);

    expect($stay->fresh()->status)->toBe(StayStatus::IN_HOUSE)
        ->and(EventLog::where('event_type', 'stay.checked_in')->where('subject_id', $stay->id)->count())->toBe(1);
});

it('lets only one of two reservations take the same free room at check-in', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $first = fdBook($hotel, $type, [null], ['departure_date' => now()->addDay()->toDateString()]);
    $second = fdBook($hotel, $type, [null], ['departure_date' => now()->addDay()->toDateString()]);
    $lifecycle = app(StayLifecycleService::class);

    $lifecycle->checkIn(fdStays($first)[0], $room->id);

    expect(fn () => $lifecycle->checkIn(fdStays($second)[0], $room->id))->toThrow(ValidationException::class);

    expect(DB::table('stays')->where('room_id', $room->id)->where('status', 'in_house')->count())->toBe(1);
});

it('makes a second check-out of the same room wait, then find it done: one departure, one cleaning task', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $lifecycle = app(StayLifecycleService::class);
    $lifecycle->checkIn($stay);

    assertWaitsForReservationLock($this, $reservation->id, fn () => $lifecycle->checkOut($stay));

    $lifecycle->checkOut($stay);
    $lifecycle->checkOut($stay);

    expect($stay->fresh()->status)->toBe(StayStatus::DEPARTED)
        ->and(EventLog::where('event_type', 'stay.checked_out')->where('subject_id', $stay->id)->count())->toBe(1)
        ->and(Task::withoutGlobalScope('hotel')->where('stay_id', $stay->id)->count())->toBe(1);
});
