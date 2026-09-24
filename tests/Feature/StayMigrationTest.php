<?php

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| The move from one stay per reservation to one per line (SPEC-023, research
| R22). Each test puts the schema back the way it was before the move, writes
| legacy-shaped rows, then runs the two migrations by hand. RefreshDatabase's
| transaction rolls the DDL back afterwards.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    DB::statement('DROP INDEX IF EXISTS stays_one_in_house_per_room');
    DB::statement('DROP INDEX IF EXISTS stays_reservation_room_id_unique');
    Schema::table('stays', fn ($table) => $table->unique('reservation_id'));
});

function stayMigration(string $name): object
{
    static $migrations = [];

    return $migrations[$name] ??= require database_path("migrations/{$name}.php");
}

function runStayBackfill(): void
{
    stayMigration('2026_09_24_000002_backfill_stays_per_reservation_room')->up();
    stayMigration('2026_09_24_000003_replace_stay_uniqueness')->up();
}

/**
 * The single stay a reservation had before SPEC-023, written straight to the
 * table with no line link.
 *
 * @param  array<string, mixed>  $attributes
 */
function legacyStay(Reservation $reservation, array $attributes = []): string
{
    $id = (string) Str::uuid();

    DB::table('stays')->insert([
        'id' => $id,
        'hotel_id' => $reservation->hotel_id,
        'guest_id' => $reservation->guest_id,
        'reservation_id' => $reservation->id,
        'room_id' => null,
        'planned_arrival_date' => $reservation->arrival_date,
        'planned_departure_date' => $reservation->departure_date,
        'status' => 'expected',
        'adults' => $reservation->adults ?? 1,
        'children' => $reservation->children ?? 0,
        'room_revenue' => $reservation->reservation_value ?? 0,
        'currency' => 'USD',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        ...$attributes,
    ]);

    return $id;
}

function migrationFixture(): array
{
    $hotel = avHotel();
    $type = avType($hotel, 'Deluxe');

    return [$hotel, $type];
}

function stayRows(Reservation $reservation)
{
    return DB::table('stays')->where('reservation_id', $reservation->id)->orderBy('created_at')->orderBy('id')->get();
}

it('links a single-line reservation\'s existing stay to its line', function () {
    [$hotel, $type] = migrationFixture();
    $reservation = createReservationWithRooms($hotel, [['room_type_id' => $type->id]]);
    $stayId = legacyStay($reservation);

    runStayBackfill();

    $line = $reservation->reservationRooms()->sole();
    expect(stayRows($reservation))->toHaveCount(1)
        ->and(DB::table('stays')->where('id', $stayId)->value('reservation_room_id'))->toBe($line->id);
});

it('gives every other line of a checked-in reservation an in-house stay with the same check-in time', function () {
    [$hotel, $type] = migrationFixture();
    [$first, $second] = avRooms($hotel, $type, 2);
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $type->id, 'room_id' => $first->id],
        ['room_type_id' => $type->id, 'room_id' => $second->id],
    ], ['status' => 'checked_in']);
    legacyStay($reservation, ['status' => 'in_house', 'checked_in_at' => '2026-09-20 14:00:00', 'room_id' => $first->id]);

    runStayBackfill();

    $stays = stayRows($reservation);
    expect($stays)->toHaveCount(2)
        ->and($stays->pluck('status')->unique()->all())->toBe(['in_house'])
        ->and($stays->pluck('checked_in_at')->unique()->all())->toBe(['2026-09-20 14:00:00'])
        ->and($stays->pluck('room_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('gives a cancelled line a cancelled stay', function () {
    [$hotel, $type] = migrationFixture();
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $type->id],
        ['room_type_id' => $type->id, 'status' => 'cancelled'],
    ]);
    legacyStay($reservation);

    runStayBackfill();

    $cancelledLine = $reservation->reservationRooms()->where('status', 'cancelled')->sole();
    expect(DB::table('stays')->where('reservation_room_id', $cancelledLine->id)->value('status'))->toBe('cancelled');
});

it('splits the value evenly with the remainder on the first line, and keeps the party on the first line', function () {
    [$hotel, $type] = migrationFixture();
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $type->id],
        ['room_type_id' => $type->id],
    ], ['reservation_value' => 100.01, 'adults' => 3, 'children' => 1]);
    legacyStay($reservation);

    runStayBackfill();

    $lines = $reservation->reservationRooms()->get();
    $firstStay = DB::table('stays')->where('reservation_room_id', $lines[0]->id)->first();
    $secondStay = DB::table('stays')->where('reservation_room_id', $lines[1]->id)->first();

    expect((float) $firstStay->room_revenue)->toBe(50.01)
        ->and((float) $secondStay->room_revenue)->toBe(50.0)
        ->and([$firstStay->adults, $firstStay->children])->toBe([3, 1])
        ->and([$secondStay->adults, $secondStay->children])->toBe([0, 0]);
});

it('gives a reservation that had no stay one stay per line', function () {
    [$hotel, $type] = migrationFixture();
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $type->id],
        ['room_type_id' => $type->id],
    ]);

    runStayBackfill();

    expect(stayRows($reservation))->toHaveCount(2)
        ->and(stayRows($reservation)->pluck('status')->unique()->all())->toBe(['expected']);
});

it('stops with the rooms listed when a room already has two in-house stays', function () {
    [$hotel, $type] = migrationFixture();
    [$room] = avRooms($hotel, $type, 1);

    foreach (['RES-ONE', 'RES-TWO'] as $code) {
        $reservation = createReservationWithRooms($hotel, [['room_type_id' => $type->id, 'room_id' => $room->id]], ['status' => 'checked_in', 'reservation_id' => $code]);
        legacyStay($reservation, ['status' => 'in_house', 'room_id' => $room->id, 'checked_in_at' => now()]);
    }

    stayMigration('2026_09_24_000002_backfill_stays_per_reservation_room')->up();

    expect(fn () => stayMigration('2026_09_24_000003_replace_stay_uniqueness')->up())
        ->toThrow(RuntimeException::class, "room {$room->id}");
});

it('rolls back to one unlinked stay per reservation', function () {
    [$hotel, $type] = migrationFixture();
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $type->id],
        ['room_type_id' => $type->id],
    ]);
    $originalId = legacyStay($reservation);

    runStayBackfill();
    stayMigration('2026_09_24_000003_replace_stay_uniqueness')->down();
    stayMigration('2026_09_24_000002_backfill_stays_per_reservation_room')->down();

    $stays = stayRows($reservation);
    expect($stays)->toHaveCount(1)
        ->and($stays->first()->id)->toBe($originalId)
        ->and($stays->first()->reservation_room_id)->toBeNull();
});
