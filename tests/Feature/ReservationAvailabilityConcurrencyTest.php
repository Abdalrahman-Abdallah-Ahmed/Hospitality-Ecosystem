<?php

use App\Exceptions\InsufficientAvailabilityException;
use App\Models\Guest;
use App\Models\ReservationRoom;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Two bookings for the last room of a type must be checked one after the
| other (SC-003). That needs data a second connection can see, so this file
| commits its rows (DatabaseTruncation) instead of wrapping each test in a
| transaction, and truncates again afterwards so nothing leaks into the
| RefreshDatabase tests that follow.
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

it('makes a second booking for the last room wait for the first, then sees it and is refused', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(6), 'channel' => 'booking_com']);

    $booking = fn (string $reference) => [
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => $reference,
        'arrival_date' => '2027-03-12',
        'departure_date' => '2027-03-13',
        'status' => 'confirmed',
    ];

    // Connection B is mid-booking and has taken the last room. It holds a
    // FOR NO KEY UPDATE lock on the type: the foreign-key checks of A's own
    // inserts do not wait for that lock, only AvailabilityService::lockTypes()
    // does, so A waiting below proves A takes the type lock before counting.
    $b = DB::connection('pgsql_b');
    $b->beginTransaction();
    $b->select('select id from room_types where id = ? for no key update', [$deluxe->id]);
    $reservationId = (string) Str::uuid();
    $b->table('reservations')->insert([
        ...$booking('RES-B'),
        'id' => $reservationId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $b->table('reservation_rooms')->insert([
        'id' => (string) Str::uuid(),
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservationId,
        'room_type_id' => $deluxe->id,
        'status' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Booking A cannot even start checking while B holds the lock.
    try {
        DB::transaction(function () use ($booking, $deluxe) {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            ReservationCreator::create($booking('RES-A'), [['room_type_id' => $deluxe->id]]);
        });
        $this->fail('Booking A should have waited for the lock.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('55P03');
    }

    // Once B commits, A sees B's booking and is refused as short.
    $b->commit();

    expect(fn () => ReservationCreator::create($booking('RES-A'), [['room_type_id' => $deluxe->id]]))
        ->toThrow(InsufficientAvailabilityException::class);

    expect(ReservationRoom::withoutGlobalScope('hotel')->where('room_type_id', $deluxe->id)->active()->count())->toBe(1);
});
