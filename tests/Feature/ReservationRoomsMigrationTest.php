<?php

use App\Enums\ReservationRoomStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The drop and backfill migrations, loaded as the objects Laravel runs.
 * Postgres DDL is transactional, so rolling the column back inside the
 * test's transaction is undone by RefreshDatabase afterwards.
 */
function reservationRoomsMigration(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

function migrationHotel(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create(['owner_id' => $owner->id, 'name' => 'Legacy Hotel', 'slug' => 'legacy-'.$owner->id, 'currency' => 'USD']);
}

/**
 * A reservation written the way the old schema stored it: one room_id.
 */
function legacyReservation(Hotel $hotel, ?string $roomId, string $status = 'confirmed', bool $trashed = false): string
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(8), 'channel' => 'booking_com']);
    $id = (string) Str::uuid();

    DB::table('reservations')->insert([
        'id' => $id,
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'room_id' => $roomId,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $trashed ? now() : null,
    ]);

    return $id;
}

beforeEach(function () {
    $this->backfill = reservationRoomsMigration('2026_09_23_000005_backfill_reservation_rooms');
    $this->drop = reservationRoomsMigration('2026_09_23_000006_drop_room_id_from_reservations_table');

    // Back to the schema the backfill runs against.
    $this->drop->down();
});

afterEach(function () {
    $this->drop->up();
});

function linesOf(string $reservationId)
{
    return ReservationRoom::withoutGlobalScope('hotel')->withTrashed()->where('reservation_id', $reservationId)->get();
}

it('gives a legacy reservation one line with its room and that room type', function () {
    $hotel = migrationHotel();
    $deluxe = RoomType::resolveFor($hotel->id, 'Deluxe');
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $deluxe->id, 'room_number' => '101']);
    $id = legacyReservation($hotel, $room->id);

    $this->backfill->up();

    $line = linesOf($id)->sole();
    expect($line->room_id)->toBe($room->id)
        ->and($line->room_type_id)->toBe($deluxe->id)
        ->and($line->status)->toBe(ReservationRoomStatus::RESERVED)
        ->and($line->hotel_id)->toBe($hotel->id);
});

it('files a reservation without a room under one inactive placeholder type per hotel', function () {
    $hotel = migrationHotel();
    $first = legacyReservation($hotel, null);
    $second = legacyReservation($hotel, null);

    $this->backfill->up();

    $placeholders = RoomType::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->where('name', 'Unspecified (migrated)')->get();

    expect($placeholders)->toHaveCount(1)
        ->and($placeholders->first()->is_active)->toBeFalse()
        ->and(linesOf($first)->sole()->room_type_id)->toBe($placeholders->first()->id)
        ->and(linesOf($second)->sole()->room_id)->toBeNull();
});

it('files a reservation whose room belongs to another hotel under the placeholder, with no room', function () {
    $hotel = migrationHotel();
    $otherHotel = migrationHotel();
    $foreignRoom = Room::create(['hotel_id' => $otherHotel->id, 'room_type_id' => roomTypeIdFor($otherHotel), 'room_number' => '101']);
    $id = legacyReservation($hotel, $foreignRoom->id);

    $this->backfill->up();

    $line = linesOf($id)->sole();
    expect($line->room_id)->toBeNull()
        ->and($line->roomType->name)->toBe('Unspecified (migrated)')
        ->and($line->roomType->hotel_id)->toBe($hotel->id);
});

it('backfills soft-deleted and cancelled reservations too', function () {
    $hotel = migrationHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $trashed = legacyReservation($hotel, $room->id, trashed: true);
    $cancelled = legacyReservation($hotel, null, 'cancelled');

    $this->backfill->up();

    expect(linesOf($trashed))->toHaveCount(1)
        ->and(linesOf($cancelled)->sole()->status)->toBe(ReservationRoomStatus::CANCELLED);
});

it('adds nothing when run a second time', function () {
    $hotel = migrationHotel();
    legacyReservation($hotel, null);
    legacyReservation($hotel, Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101'])->id);

    $this->backfill->up();
    $count = ReservationRoom::withoutGlobalScope('hotel')->count();
    $this->backfill->up();

    expect(ReservationRoom::withoutGlobalScope('hotel')->count())->toBe($count)->toBe(2)
        ->and(RoomType::withoutGlobalScope('hotel')->where('name', 'Unspecified (migrated)')->count())->toBe(1);
});

it('leaves every room status as it was', function () {
    $hotel = migrationHotel();
    $occupied = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101', 'status' => 'occupied']);
    $available = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '102']);
    legacyReservation($hotel, $occupied->id, 'checked_in');
    legacyReservation($hotel, $available->id);

    $before = Room::pluck('status', 'id')->all();
    $this->backfill->up();

    expect(Room::pluck('status', 'id')->all())->toBe($before);
});

it('restores the single room column from the first line on rollback', function () {
    $hotel = migrationHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $id = legacyReservation($hotel, $room->id);
    $this->backfill->up();

    $this->drop->up();
    $this->drop->down();

    expect(DB::table('reservations')->where('id', $id)->value('room_id'))->toBe($room->id);
});
