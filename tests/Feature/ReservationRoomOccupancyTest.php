<?php

use App\Enums\HousekeepingStatusesEnum;
use App\Jobs\MakeRoomDirtyOvernightJob;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('does not occupy the room for a merely confirmed reservation, only once the guest actually checks in', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-CONFIRMED-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'confirmed',
        ])->assertCreated();

    // Booked ahead of arrival is not the same as physically occupying the
    // room — that's the whole distinction a Stay exists to capture.
    expect($room->fresh()->status)->toBe('available');
});

it('occupies the room once the reservation is updated to checked_in', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-CHECKIN-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'confirmed',
        ]);

    $reservationId = $response->json('body.id');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservationId}", ['status' => 'checked_in'])
        ->assertOk();

    expect($room->fresh()->status)->toBe('occupied');
});

it('frees the room back to available once the guest checks out', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-CHECKOUT-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'checked_in',
        ]);

    $reservationId = $response->json('body.id');
    expect($room->fresh()->status)->toBe('occupied');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservationId}", ['status' => 'checked_out'])
        ->assertOk();

    // Previously nothing ever freed the room back up automatically.
    expect($room->fresh()->status)->toBe('available');
});

it('frees the room back to available when a checked-in reservation is cancelled', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-CANCEL-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'checked_in',
        ]);

    $reservationId = $response->json('body.id');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservationId}", ['status' => 'cancelled'])
        ->assertOk();

    expect($room->fresh()->status)->toBe('available');
});

it('keeps a room occupied when a future reservation is booked into it', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $tonight = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);
    $nextWeek = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-2', 'channel' => 'booking_com']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $tonight->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-TONIGHT-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'checked_in',
        ])->assertCreated();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $nextWeek->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-NEXTWEEK-01',
            'arrival_date' => '2026-09-10',
            'departure_date' => '2026-09-12',
            'status' => 'confirmed',
        ])->assertCreated();

    // Booking the room for next week says nothing about who is in it tonight.
    expect($room->fresh()->status)->toBe('occupied');
});

it('frees the old room and occupies the new one when a checked-in guest moves rooms', function () {
    [$admin, $hotel] = adminWithHotel();
    $oldRoom = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $newRoom = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '102']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $reservationId = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $oldRoom->id]],
            'reservation_id' => 'RES-MOVE-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'checked_in',
        ])->json('body.id');

    $lineId = ReservationRoom::where('reservation_id', $reservationId)->value('id');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservationId}", ['rooms' => [['id' => $lineId, 'room_id' => $newRoom->id]]])
        ->assertOk();

    expect($oldRoom->fresh()->status)->toBe('available')
        ->and($newRoom->fresh()->status)->toBe('occupied');
});

it('leaves a room under maintenance alone when a future reservation is booked into it', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101', 'status' => 'maintenance']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => roomTypeIdFor($hotel), 'room_id' => $room->id]],
            'reservation_id' => 'RES-REPAIR-01',
            'arrival_date' => '2026-09-10',
            'departure_date' => '2026-09-12',
            'status' => 'confirmed',
        ])->assertCreated();

    expect($room->fresh()->status)->toBe('maintenance');
});

// Multi-room reservations

/**
 * @return array{0: string, 1: array<int, Room>}
 */
function checkedInThreeRooms($test, $admin, $hotel): array
{
    $rooms = collect(['201', '202', '203'])->map(fn ($number) => Room::create([
        'hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => $number,
    ]))->all();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-3', 'channel' => 'booking_com']);

    $id = $test->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'RES-THREE-01',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'checked_in',
            'adults' => 3,
            'rooms' => collect($rooms)->map(fn (Room $room) => ['room_type_id' => $room->room_type_id, 'room_id' => $room->id])->all(),
        ])->assertCreated()->json('body.id');

    return [$id, $rooms];
}

it('occupies every room of a checked-in multi-room reservation', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $rooms] = checkedInThreeRooms($this, $admin, $hotel);

    foreach ($rooms as $room) {
        expect($room->fresh()->status)->toBe('occupied');
    }
});

it('releases every room when a multi-room reservation is cancelled', function () {
    [$admin, $hotel] = adminWithHotel();
    [$id, $rooms] = checkedInThreeRooms($this, $admin, $hotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$id}", ['status' => 'cancelled'])
        ->assertOk();

    foreach ($rooms as $room) {
        expect($room->fresh()->status)->toBe('available');
    }
});

it('dirties every room of a multi-room reservation overnight', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $rooms] = checkedInThreeRooms($this, $admin, $hotel);

    (new MakeRoomDirtyOvernightJob)->handle();

    foreach ($rooms as $room) {
        expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
    }
});

it('counts every room of a multi-room reservation in historic occupancy', function () {
    [$admin, $hotel] = adminWithHotel();
    checkedInThreeRooms($this, $admin, $hotel);

    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2026-09-02')))->toBe(3);
});

it('points the single stay at the first line room', function () {
    [$admin, $hotel] = adminWithHotel();
    [$id, $rooms] = checkedInThreeRooms($this, $admin, $hotel);

    $reservation = Reservation::find($id);

    expect($reservation->stay->room_id)->toBe($reservation->primaryRoomId())
        ->and($reservation->primaryRoomId())->toBe($rooms[0]->id);
});
