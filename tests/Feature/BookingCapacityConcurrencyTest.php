<?php

use App\Enums\ActivityUnavailableReason;
use App\Exceptions\ActivityUnavailableException;
use App\Models\Booking;
use App\Services\ActivityAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
| Two desks booking the last places at the same moment must not both succeed
| (FR-009, SC-002). A second connection holds the activity lock the booking
| takes, which needs committed rows, so this file uses DatabaseTruncation
| (like CheckInOutConcurrencyTest) and truncates afterwards.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
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

it('makes a booking wait for the activity lock instead of reading around it', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);
    $date = now()->addDays(3)->toDateString();

    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->select('select id from activities where id = ? for update', [$activity->id]);

    try {
        DB::transaction(function () use ($hotel, $activity, $date) {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            abBook($hotel, $activity, $date, 1);
        });
        $this->fail('The booking should have waited for the activity lock.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('55P03');
    }

    $b->commit();
});

it('lets only one of two bookings take the last places', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);
    $date = now()->addDays(3)->toDateString();
    $guest = abGuest($hotel);

    // Desk B holds the lock and takes both places, then commits.
    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->select('select id from activities where id = ? for update', [$activity->id]);
    $b->table('bookings')->insert([
        'id' => (string) str()->uuid(),
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'reference' => 'DSK-B001',
        'item_name' => $activity->name,
        'status' => 'pending',
        'scheduled_date' => $date,
        'last_date' => $date,
        'pax' => 2,
        'charge_model' => 'pay_on_site',
        'origin' => 'staff',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $b->commit();

    // Desk A, checking after B's commit, sees the places gone.
    try {
        abBook($hotel, $activity, $date, 1);
        $this->fail('The second booking should have been refused.');
    } catch (ActivityUnavailableException $e) {
        expect($e->reason)->toBe(ActivityUnavailableReason::FULLY_BOOKED);
    }

    expect(Booking::withoutGlobalScope('hotel')->where('activity_id', $activity->id)->sum('pax'))->toBe(2)
        ->and(app(ActivityAvailabilityService::class)->day($activity, $date)->remaining)->toBe(0);
});
