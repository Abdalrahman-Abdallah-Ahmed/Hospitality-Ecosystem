<?php

use App\Enums\ReservationRoomStatus;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function rrHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

/**
 * @return array{0: User, 1: Hotel, 2: Guest}
 */
function rrAdmin(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Rooms Hotel',
        'slug' => 'rooms-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(6), 'channel' => 'booking_com']);

    return [$admin->fresh(), $hotel, $guest];
}

function rrType(Hotel $hotel, string $name, int $maxOccupancy = 2, int $adults = 2, bool $active = true): RoomType
{
    return RoomType::create([
        'hotel_id' => $hotel->id,
        'name' => $name,
        'max_occupancy' => $maxOccupancy,
        'adult_capacity' => $adults,
        'child_capacity' => $maxOccupancy - $adults,
        'base_price' => 100,
        'is_active' => $active,
    ]);
}

function rrRoom(Hotel $hotel, RoomType $type, string $number): Room
{
    return Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_number' => $number]);
}

function rrPayload(Hotel $hotel, Guest $guest, array $rooms, array $overrides = []): array
{
    return [
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => '2026-10-01',
        'departure_date' => '2026-10-04',
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        'rooms' => $rooms,
        ...$overrides,
    ];
}

function rrCreate($test, User $admin, array $payload)
{
    return $test->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', $payload);
}

function rrUpdate($test, User $admin, string $id, array $payload)
{
    return $test->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')->putJson("/api/reservation/{$id}", $payload);
}

// US1 — create by room type

it('creates one line per booked unit, all unassigned, with a per-type summary', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite', 4, 4);

    $response = rrCreate($this, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $deluxe->id, 'quantity' => 2],
        ['room_type_id' => $suite->id],
    ]))->assertCreated();

    $lines = collect($response->json('body.rooms'));
    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('room_id')->filter())->toBeEmpty()
        ->and($lines->pluck('status')->unique()->all())->toBe(['reserved'])
        ->and($lines->pluck('room_type_id')->countBy()->all())->toBe([$deluxe->id => 2, $suite->id => 1]);

    $summary = collect($response->json('body.room_summary'))->keyBy('room_type_name');
    expect($summary['Deluxe']['quantity'])->toBe(2)
        ->and($summary['Suite']['quantity'])->toBe(1);
});

it('shows each line with its room type, room and status', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room = rrRoom($hotel, $deluxe, '101');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $deluxe->id, 'room_id' => $room->id],
    ]))->json('body.id');

    $this->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation/{$id}")
        ->assertOk()
        ->assertJsonPath('body.rooms.0.room_type.name', 'Deluxe')
        ->assertJsonPath('body.rooms.0.room.room_number', '101')
        ->assertJsonPath('body.rooms.0.status', 'reserved');
});

it('rejects a reservation with no rooms', function () {
    [$admin, $hotel, $guest] = rrAdmin();

    rrCreate($this, $admin, rrPayload($hotel, $guest, []))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms']);
});

it('rejects a room type that is inactive, deleted or of another hotel, saving nothing', function (string $case) {
    [$admin, $hotel, $guest] = rrAdmin();
    [, $otherHotel] = rrAdmin();

    $type = match ($case) {
        'inactive' => rrType($hotel, 'Old', active: false),
        'deleted' => tap(rrType($hotel, 'Gone'))->delete(),
        'foreign' => rrType($otherHotel, 'Theirs'),
    };

    $payload = rrPayload($hotel, $guest, [['room_type_id' => $type->id]]);

    rrCreate($this, $admin, $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.0.room_type_id' => 'The selected room type is not available.']);

    expect(Reservation::where('reservation_id', $payload['reservation_id'])->exists())->toBeFalse();
})->with(['inactive', 'deleted', 'foreign']);

it('rejects a party larger than the booked rooms hold', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id, 'quantity' => 2]], ['adults' => 5]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms'])
        ->assertJsonFragment(['The party (5 adults, 0 children) is larger than the booked rooms hold (4 guests, 4 adults). Add rooms, or send capacity_override to save it anyway.']);
});

it('saves an over-capacity reservation with a staff override and audits it', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id, 'quantity' => 2]], [
        'adults' => 5,
        'capacity_override' => true,
    ]))->assertCreated()->json('body.id');

    $event = EventLog::where('event_type', 'reservation.capacity_overridden')->where('subject_id', $id)->firstOrFail();

    expect($event->actor_kind->value ?? $event->actor_kind)->toBe('user')
        ->and($event->changes)->toBe(['adults' => 5, 'children' => 0, 'max_occupancy' => 4, 'adult_capacity' => 4]);
});

it('rejects more than 50 rooms', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    rrCreate($this, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $double->id, 'quantity' => 50],
        ['room_type_id' => $double->id, 'quantity' => 1],
    ]))->assertStatus(422)->assertJsonValidationErrors(['rooms']);
});

it('rejects a physical room on a line with a quantity above 1', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');
    $room = rrRoom($hotel, $double, '101');

    rrCreate($this, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $double->id, 'quantity' => 2, 'room_id' => $room->id],
    ]))->assertStatus(422)->assertJsonValidationErrors(['rooms.0.room_id']);
});

it('rejects the old single room_id field', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');
    $room = rrRoom($hotel, $double, '101');

    rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id]], ['room_id' => $room->id]))
        ->assertStatus(422)
        ->assertJsonPath('errors.room_id.0', 'Use rooms[] instead.');
});

it('audits one created event per line', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id, 'quantity' => 3]], ['adults' => 1]))
        ->assertCreated()->json('body.id');

    $lineIds = ReservationRoom::where('reservation_id', $id)->pluck('id');

    expect(EventLog::where('event_type', 'reservation_room.created')->whereIn('subject_id', $lineIds)->count())->toBe(3);
});

it('leaves no reservation and no lines when the write fails part-way', function () {
    [, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    // A room type deleted between validation and insert makes the line
    // insert fail after the reservation row is already written.
    ReservationRoom::creating(function () {
        throw new RuntimeException('boom');
    });

    expect(fn () => ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-ATOMIC01',
        'arrival_date' => '2026-10-01',
        'departure_date' => '2026-10-04',
    ], [['room_type_id' => $double->id]]))->toThrow(RuntimeException::class);

    expect(Reservation::withoutGlobalScope('hotel')->where('reservation_id', 'RES-ATOMIC01')->exists())->toBeFalse()
        ->and(ReservationRoom::withoutGlobalScope('hotel')->count())->toBe(0);
});

// Filters

it('filters reservations by any live line of a room type or room', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite');
    $room = rrRoom($hotel, $deluxe, '101');

    $withDeluxe = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id, 'room_id' => $room->id], ['room_type_id' => $suite->id]]))->json('body.id');
    $suiteOnly = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $suite->id]]))->json('body.id');
    $cancelledDeluxe = createReservationWithRooms($hotel, [
        ['room_type_id' => $deluxe->id, 'status' => 'cancelled'],
        ['room_type_id' => $suite->id],
    ], ['guest_id' => $guest->id]);

    $byType = $this->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation?filter[room_type_id]={$deluxe->id}")->assertOk();
    expect(collect($byType->json('body.data'))->pluck('id')->all())->toBe([$withDeluxe]);

    $byRoom = $this->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation?filter[room_id]={$room->id}")->assertOk();
    expect(collect($byRoom->json('body.data'))->pluck('id')->all())->toBe([$withDeluxe]);

    expect($suiteOnly)->not->toBeNull()->and($cancelledDeluxe->id)->not->toBeNull();
});

it('does not allow sorting by a line filter', function () {
    [$admin] = rrAdmin();

    $this->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/reservation?sort=room_type_id')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sort']);
});

// US3 — changing lines

function rrReservationWithTwoDeluxe($test, User $admin, Hotel $hotel, Guest $guest, RoomType $deluxe, Room $room, string $status = 'confirmed'): array
{
    $response = rrCreate($test, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $deluxe->id, 'room_id' => $room->id],
        ['room_type_id' => $deluxe->id],
    ], ['status' => $status]))->assertCreated();

    $lines = collect($response->json('body.rooms'));

    return [
        $response->json('body.id'),
        $lines->firstWhere('room_id', $room->id)['id'],
        $lines->firstWhere('room_id', null)['id'],
    ];
}

it('adds a line and cancels an omitted one, leaving the kept line untouched', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite');
    $room = rrRoom($hotel, $deluxe, '101');
    [$id, $assigned, $unassigned] = rrReservationWithTwoDeluxe($this, $admin, $hotel, $guest, $deluxe, $room);

    rrUpdate($this, $admin, $id, ['rooms' => [
        ['id' => $assigned],
        ['room_type_id' => $suite->id],
    ]])->assertOk();

    expect(ReservationRoom::find($assigned)->room_id)->toBe($room->id)
        ->and(ReservationRoom::find($unassigned)->status)->toBe(ReservationRoomStatus::CANCELLED)
        ->and(ReservationRoom::where('reservation_id', $id)->active()->pluck('room_type_id')->sort()->values()->all())
        ->toBe(collect([$deluxe->id, $suite->id])->sort()->values()->all());

    expect(EventLog::where('event_type', 'reservation_room.updated')->where('subject_id', $unassigned)->exists())->toBeTrue();
});

it('releases the room of a cancelled line', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room = rrRoom($hotel, $deluxe, '101');
    [$id, $assigned, $unassigned] = rrReservationWithTwoDeluxe($this, $admin, $hotel, $guest, $deluxe, $room);

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $unassigned]]])->assertOk();

    expect(ReservationRoom::find($assigned)->status)->toBe(ReservationRoomStatus::CANCELLED)
        ->and(Reservation::find($id)->primaryRoomId())->toBeNull();
});

it('refuses to remove the last line', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id]]))->json('body.id');

    rrUpdate($this, $admin, $id, ['rooms' => []])
        ->assertStatus(422)
        ->assertJsonPath('errors.rooms.0', 'A reservation needs at least one room; cancel the reservation instead.');

    expect(ReservationRoom::where('reservation_id', $id)->active()->count())->toBe(1);
});

it('rejects a line id that is not a live line of the reservation', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id]]))->json('body.id');

    // Replace the line, then point at the cancelled one.
    rrUpdate($this, $admin, $id, ['rooms' => [['room_type_id' => $deluxe->id]]])->assertOk();
    $cancelled = ReservationRoom::where('reservation_id', $id)->where('status', 'cancelled')->value('id');

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $cancelled]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.0.id']);
});

it('rejects line changes on a checked-out or cancelled reservation', function (string $status) {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id]], ['status' => $status]))->json('body.id');
    $line = ReservationRoom::where('reservation_id', $id)->value('id');

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $line], ['room_type_id' => $deluxe->id]]])
        ->assertStatus(422)
        ->assertJsonFragment(["Rooms cannot be changed on a {$status} reservation."]);
})->with(['checked_out', 'cancelled']);

it('rejects adding, removing or retyping lines on a checked-in reservation', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite');
    $room = rrRoom($hotel, $deluxe, '101');
    [$id, $assigned, $unassigned] = rrReservationWithTwoDeluxe($this, $admin, $hotel, $guest, $deluxe, $room, 'checked_in');

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $assigned], ['id' => $unassigned], ['room_type_id' => $suite->id]]])
        ->assertStatus(422);
    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $assigned]]])
        ->assertStatus(422);
    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $assigned], ['id' => $unassigned, 'room_type_id' => $suite->id]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.1.room_type_id']);
});

it('keeps the lines when rooms is not sent, and follows the reservation dates', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id, 'quantity' => 2]], ['adults' => 1]))->json('body.id');

    rrUpdate($this, $admin, $id, ['departure_date' => '2026-10-10'])->assertOk()
        ->assertJsonCount(2, 'body.rooms');

    expect(ReservationRoom::where('reservation_id', $id)->active()->count())->toBe(2);
});

it('cancels every line when the reservation is cancelled', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room = rrRoom($hotel, $deluxe, '101');
    [$id] = rrReservationWithTwoDeluxe($this, $admin, $hotel, $guest, $deluxe, $room, 'checked_in');
    expect($room->fresh()->status)->toBe('occupied');

    rrUpdate($this, $admin, $id, ['status' => 'cancelled'])->assertOk();

    expect(ReservationRoom::where('reservation_id', $id)->active()->count())->toBe(0)
        ->and($room->fresh()->status)->toBe('available');
});

it('re-checks capacity when the party grows', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id]]))->json('body.id');

    rrUpdate($this, $admin, $id, ['adults' => 3])->assertStatus(422)->assertJsonValidationErrors(['rooms']);
    expect(Reservation::find($id)->adults)->toBe(2);

    rrUpdate($this, $admin, $id, ['adults' => 3, 'capacity_override' => true])->assertOk();
});

// US4 — physical rooms on lines and room moves

it('sets and clears a room on a line of a confirmed reservation', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room101 = rrRoom($hotel, $deluxe, '101');
    $room102 = rrRoom($hotel, $deluxe, '102');
    [$id, $assigned, $unassigned] = rrReservationWithTwoDeluxe($this, $admin, $hotel, $guest, $deluxe, $room101);

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $assigned, 'room_id' => null], ['id' => $unassigned, 'room_id' => $room102->id]]])
        ->assertOk();

    expect(ReservationRoom::find($assigned)->room_id)->toBeNull()
        ->and(ReservationRoom::find($unassigned)->room_id)->toBe($room102->id);
});

it('rejects a room of another type, another hotel, or one that does not exist', function (string $case) {
    [$admin, $hotel, $guest] = rrAdmin();
    [, $otherHotel] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite');

    $roomId = match ($case) {
        'type' => rrRoom($hotel, $suite, '501')->id,
        'hotel' => rrRoom($otherHotel, rrType($otherHotel, 'Deluxe'), '101')->id,
        'missing' => (string) Str::uuid(),
    };

    rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id, 'room_id' => $roomId]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.0.room_id']);
})->with(['type', 'hotel', 'missing']);

it('moves a checked-in guest to another room of the same type', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room101 = rrRoom($hotel, $deluxe, '101');
    $room105 = rrRoom($hotel, $deluxe, '105');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id, 'room_id' => $room101->id]], ['status' => 'checked_in']))->json('body.id');
    $line = ReservationRoom::where('reservation_id', $id)->value('id');
    expect($room101->fresh()->status)->toBe('occupied');

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $line, 'room_id' => $room105->id]]])->assertOk();

    expect($room101->fresh()->status)->toBe('available')
        ->and($room105->fresh()->status)->toBe('occupied');

    $event = EventLog::where('event_type', 'reservation_room.updated')->where('subject_id', $line)->latest('occurred_at')->firstOrFail();
    expect($event->changes['room_id'] ?? null)->not->toBeNull();
});

it('rejects moving a checked-in guest to another type, or clearing their room', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $suite = rrType($hotel, 'Suite');
    $room101 = rrRoom($hotel, $deluxe, '101');
    $suiteRoom = rrRoom($hotel, $suite, '501');
    $id = rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $deluxe->id, 'room_id' => $room101->id]], ['status' => 'checked_in']))->json('body.id');
    $line = ReservationRoom::where('reservation_id', $id)->value('id');

    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $line, 'room_id' => $suiteRoom->id]]])
        ->assertStatus(422)->assertJsonValidationErrors(['rooms.0.room_id']);
    rrUpdate($this, $admin, $id, ['rooms' => [['id' => $line, 'room_id' => null]]])
        ->assertStatus(422)->assertJsonValidationErrors(['rooms.0.room_id']);
});

it('rejects the same room on two lines, and the database refuses it too', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $deluxe = rrType($hotel, 'Deluxe');
    $room = rrRoom($hotel, $deluxe, '101');

    rrCreate($this, $admin, rrPayload($hotel, $guest, [
        ['room_type_id' => $deluxe->id, 'room_id' => $room->id],
        ['room_type_id' => $deluxe->id, 'room_id' => $room->id],
    ]))->assertStatus(422)->assertJsonValidationErrors(['rooms.1.room_id']);

    $reservation = createReservationWithRooms($hotel, [['room_type_id' => $deluxe->id, 'room_id' => $room->id]]);

    expect(fn () => DB::transaction(fn () => ReservationRoom::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'room_type_id' => $deluxe->id,
        'room_id' => $room->id,
    ])))->toThrow(QueryException::class);
});

// Performance

it('lists reservations without a query per reservation, and books 50 rooms at once', function () {
    [$admin, $hotel, $guest] = rrAdmin();
    $double = rrType($hotel, 'Double');

    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders(rrHeaders())->actingAs($admin, 'sanctum')->getJson('/api/reservation')->assertOk();

        return count(DB::getQueryLog());
    };

    createReservationWithRooms($hotel, [['room_type_id' => $double->id], ['room_type_id' => $double->id]], ['guest_id' => $guest->id]);
    $one = $count();

    foreach (range(1, 9) as $i) {
        createReservationWithRooms($hotel, [['room_type_id' => $double->id], ['room_type_id' => $double->id], ['room_type_id' => $double->id]], ['guest_id' => $guest->id]);
    }
    $ten = $count();

    expect($ten)->toBe($one);

    rrCreate($this, $admin, rrPayload($hotel, $guest, [['room_type_id' => $double->id, 'quantity' => 50]]))
        ->assertCreated()
        ->assertJsonCount(50, 'body.rooms');
});
