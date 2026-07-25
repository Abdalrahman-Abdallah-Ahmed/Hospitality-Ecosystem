<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function apiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);

    return [$admin, $hotel];
}

function reservationFor(Hotel $hotel, array $overrides = []): Reservation
{
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.$hotel->id,
        'channel' => 'booking_com',
    ]);

    return Reservation::create(array_merge([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Illuminate\Support\Str::random(8)),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ], $overrides));
}

// index

it('rejects an unauthenticated index request', function () {
    $this->withHeaders(apiHeaders())->getJson('/api/reservation')
        ->assertStatus(401);
});

it('rejects a non-admin user from listing reservations', function () {
    $worker = User::factory()->role(UserRole::WORKER)->create();

    $this->withHeaders(apiHeaders())->actingAs($worker, 'sanctum')
        ->getJson('/api/reservation')
        ->assertStatus(403);
});

it('only lists reservations belonging to the admin own hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    $mine = reservationFor($hotel);

    [$otherAdmin, $otherHotel] = adminWithHotel();
    reservationFor($otherHotel);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/reservation');

    $response->assertOk();
    $ids = collect($response->json('body.data'))->pluck('id');
    expect($ids)->toHaveCount(1);
    expect($ids)->toContain($mine->id);
});

// store

it('creates a reservation for the admin hotel with valid data', function () {
    [$admin, $hotel] = adminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'RES-ABC12345',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(201)->assertJsonPath('body.hotel_id', $hotel->id);
    expect(Reservation::where('reservation_id', 'RES-ABC12345')->exists())->toBeTrue();
});

it('rejects a reservation missing required schema-derived fields', function () {
    [$admin, $hotel] = adminWithHotel();

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['hotel_id', 'guest_id', 'reservation_id']);
});

it('rejects a reservation with an invalid status enum value', function () {
    [$admin, $hotel] = adminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'RES-ABC12345',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'not-a-real-status',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['status']);
});

/**
 * BUG: store() only checks Reservation::class-level "create" authorization
 * (admin role), and GenericStoreRequest only validates that guest_id/room_id
 * *exist somewhere*, not that they belong to the same hotel as hotel_id.
 * An admin can therefore attach a guest or room from a hotel they do not
 * own to a reservation. This test documents the current (insecure) behavior.
 */
it('BUG: allows creating a reservation with a guest from a different hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $foreignGuest = Guest::create(['hotel_id' => $otherHotel->id, 'external_id' => 'ext-foreign', 'channel' => 'booking_com']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $foreignGuest->id,
            'reservation_id' => 'RES-ABC99999',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(201);
    expect(Reservation::where('reservation_id', 'RES-ABC99999')->first()->guest_id)->toBe($foreignGuest->id);
});

/**
 * BUG: same root cause as above — room_id is only checked with
 * exists:rooms,id, not that the room belongs to hotel_id.
 */
it('BUG: allows creating a reservation with a room from a different hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);
    $foreignRoom = Room::create(['hotel_id' => $otherHotel->id, 'room_number' => '101']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $foreignRoom->id,
            'reservation_id' => 'RES-ABC88888',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(201);
    expect(Reservation::where('reservation_id', 'RES-ABC88888')->first()->room_id)->toBe($foreignRoom->id);
});

// show

it('lets an admin view a reservation belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    $reservation = reservationFor($hotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation/{$reservation->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $reservation->id);
});

it('rejects an admin viewing a reservation belonging to a different hotel', function () {
    [$admin] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $reservation = reservationFor($otherHotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation/{$reservation->id}")
        ->assertStatus(403);
});

// update

it('lets an admin partially update a reservation belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    $reservation = reservationFor($hotel);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", [
            'status' => 'confirmed',
            'adults' => 3,
        ]);

    $response->assertOk()->assertJsonPath('body.status', 'confirmed');
    expect($reservation->fresh()->adults)->toBe(3);
});

it('rejects an admin updating a reservation belonging to a different hotel', function () {
    [$admin] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $reservation = reservationFor($otherHotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['adults' => 5])
        ->assertStatus(403);

    expect($reservation->fresh()->adults)->toBe(1);
});

it('rejects an invalid status enum value on update', function () {
    [$admin, $hotel] = adminWithHotel();
    $reservation = reservationFor($hotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['status' => 'not-a-real-status'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('does not flag the reservation unique id rule against itself on update', function () {
    [$admin, $hotel] = adminWithHotel();
    $reservation = reservationFor($hotel, ['reservation_id' => 'RES-KEEPSAME']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['reservation_id' => 'RES-KEEPSAME'])
        ->assertOk();
});

/**
 * BUG: the authorize('update', $reservation) check only validates the
 * CURRENT hotel_id of the reservation being updated. GenericUpdateRequest
 * exposes hotel_id as an updatable column (exists:hotels,id only), so an
 * admin authorized to update their own reservation can reassign it to a
 * hotel they do not own.
 */
it('BUG: allows an admin to reassign a reservation to a hotel they do not own', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $reservation = reservationFor($hotel);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", [
            'hotel_id' => $otherHotel->id,
        ]);

    $response->assertOk();
    expect($reservation->fresh()->hotel_id)->toBe($otherHotel->id);
});

// destroy

it('lets an admin delete a reservation belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    $reservation = reservationFor($hotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/reservation/{$reservation->id}")
        ->assertOk();

    expect(Reservation::find($reservation->id))->toBeNull();
});

it('rejects an admin deleting a reservation belonging to a different hotel', function () {
    [$admin] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $reservation = reservationFor($otherHotel);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/reservation/{$reservation->id}")
        ->assertStatus(403);

    expect(Reservation::find($reservation->id))->not->toBeNull();
});
