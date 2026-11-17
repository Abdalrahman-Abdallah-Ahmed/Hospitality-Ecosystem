<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function roomTypeAdmin(): array
{
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    return [$admin, $hotel];
}

function roomTypeHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

test('test_create_room_type_success', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $response = $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Standard Room',
            'description' => 'Comfortable room for single travelers',
            'max_occupancy' => 2,
            'adult_capacity' => 1,
            'child_capacity' => 1,
            'base_price' => 80.00,
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('body.name', 'Standard Room');
    $response->assertJsonPath('body.is_active', true);
});

test('test_create_room_type_validation_failure', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', ['description' => 'Missing required fields'])
        ->assertStatus(422);
});

test('test_list_room_types_paginated', function () {
    [$admin, $hotel] = roomTypeAdmin();
    RoomType::factory()->count(3)->create(['hotel_id' => $hotel->id]);

    $response = $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/room-types?page=1&per_page=25');

    $response->assertStatus(200);
    $response->assertJsonIsArray('body.data');
    expect($response->json('body.data'))->toHaveCount(3);
});

test('test_get_single_room_type', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/room-types/{$roomType->id}")
        ->assertStatus(200)
        ->assertJsonPath('body.id', $roomType->id);
});

test('test_update_room_type_fields', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 80.00]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room-types/{$roomType->id}", ['base_price' => 95.00])
        ->assertStatus(200)
        ->assertJsonPath('body.base_price', '95.00');
});

test('test_soft_delete_room_type', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(200);

    expect(RoomType::find($roomType->id))->toBeNull();
});

test('test_soft_deleted_not_in_list', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(200);

    $response = $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/room-types');

    $response->assertStatus(200);
    expect($response->json('body.data'))->toHaveCount(0);
});

test('test_create_with_invalid_capacity_sum', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Invalid Room',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 1,
            'base_price' => 50.00,
        ])
        ->assertStatus(422);
});

test('test_create_with_zero_adult_capacity', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Invalid Room',
            'max_occupancy' => 1,
            'adult_capacity' => 0,
            'child_capacity' => 1,
            'base_price' => 50.00,
        ])
        ->assertStatus(422);
});

test('test_delete_blocked_by_active_rooms', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(422)
        ->assertJsonPath('body.error', 'deletion_blocked_by_rooms');
});

test('test_delete_blocked_by_current_or_upcoming_reservations', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    createReservationWithRooms($hotel, [['room_type_id' => $roomType->id]], [
        'arrival_date' => now()->addDays(5)->toDateString(),
        'departure_date' => now()->addDays(8)->toDateString(),
    ]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(422)
        ->assertJsonPath('body.error', 'deletion_blocked_by_reservations')
        ->assertJsonPath('body.reservations', 1);

    expect($roomType->fresh()->trashed())->toBeFalse();
});

test('test_delete_allowed_when_only_past_or_cancelled_reservations_use_it', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    createReservationWithRooms($hotel, [['room_type_id' => $roomType->id]], [
        'arrival_date' => now()->subDays(8)->toDateString(),
        'departure_date' => now()->subDays(5)->toDateString(),
    ]);
    createReservationWithRooms($hotel, [['room_type_id' => $roomType->id, 'status' => 'cancelled']], [
        'arrival_date' => now()->addDays(5)->toDateString(),
        'departure_date' => now()->addDays(8)->toDateString(),
    ]);
    createReservationWithRooms($hotel, [['room_type_id' => $roomType->id]], [
        'status' => 'cancelled',
        'arrival_date' => now()->addDays(5)->toDateString(),
        'departure_date' => now()->addDays(8)->toDateString(),
    ]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertOk();

    expect(RoomType::withTrashed()->find($roomType->id)->trashed())->toBeTrue();
});

test('test_create_rejects_a_name_already_used_in_the_hotel', function () {
    [$admin, $hotel] = roomTypeAdmin();
    RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe']);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'deluxe',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 50.00,
        ])
        ->assertStatus(422);

    // Another hotel may use the same name.
    [$otherAdmin, $otherHotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($otherAdmin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $otherHotel->id,
            'name' => 'Deluxe',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 50.00,
        ])
        ->assertStatus(201);
});

test('test_create_reuses_the_name_of_a_deleted_room_type', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $deleted = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe']);
    $deleted->delete();

    $response = $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'deluxe',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 50.00,
        ])
        ->assertStatus(201);

    expect($response->json('body.id'))->not->toBe($deleted->id);
    expect($deleted->fresh()->trashed())->toBeTrue();
});

test('test_create_treats_a_null_is_active_as_omitted', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Suite',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 50.00,
            'is_active' => null,
        ])
        ->assertStatus(201)
        ->assertJsonPath('body.is_active', true);
});

test('test_create_rejects_a_negative_price', function () {
    [$admin, $hotel] = roomTypeAdmin();

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Cheap Room',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => -1,
        ])
        ->assertStatus(422);
});

test('test_update_checks_capacity_against_the_stored_values', function () {
    [$admin, $hotel] = roomTypeAdmin();
    $roomType = RoomType::factory()->create([
        'hotel_id' => $hotel->id,
        'max_occupancy' => 3,
        'adult_capacity' => 2,
        'child_capacity' => 1,
    ]);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room-types/{$roomType->id}", ['max_occupancy' => 2])
        ->assertStatus(422);

    expect($roomType->fresh()->max_occupancy)->toBe(3);

    // Keeping its own name is not a duplicate.
    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room-types/{$roomType->id}", ['name' => $roomType->name, 'max_occupancy' => 4])
        ->assertStatus(200);
});

test('test_create_a_second_room_type_in_the_same_hotel', function () {
    [$admin, $hotel] = roomTypeAdmin();
    RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Standard']);

    $this->withHeaders(roomTypeHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room-types', [
            'hotel_id' => $hotel->id,
            'name' => 'Deluxe',
            'description' => null,
            'max_occupancy' => 4,
            'adult_capacity' => 4,
            'child_capacity' => 0,
            'amenities' => null,
            'base_price' => 1800,
            'bed_configuration' => ['beds' => [['type' => 'king', 'count' => 2]]],
            'is_active' => true,
        ])
        ->assertStatus(201)
        ->assertJsonPath('body.name', 'Deluxe');
});
