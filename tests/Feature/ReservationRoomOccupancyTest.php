<?php

use App\Models\Guest;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('does not occupy the room for a merely confirmed reservation, only once the guest actually checks in', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room->id,
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
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room->id,
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
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room->id,
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
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room->id,
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
