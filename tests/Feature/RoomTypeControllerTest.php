<?php

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class)->beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

test('test_create_room_type_success', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->postJson('/api/room-types', [
        'name' => 'Standard Room',
        'description' => 'Comfortable room for single travelers',
        'max_occupancy' => 2,
        'adult_capacity' => 1,
        'child_capacity' => 1,
        'base_price' => 80.00,
    ], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('body.name', 'Standard Room');
    $response->assertJsonPath('body.is_active', true);
});

test('test_create_room_type_validation_failure', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->postJson('/api/room-types', [
        'description' => 'Missing required fields',
    ], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(422);
});

test('test_list_room_types_paginated', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    RoomType::factory()->count(3)->create(['hotel_id' => $hotel->id]);

    $response = $this->getJson('/api/room-types?page=1&per_page=25', [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $response->assertJsonIsArray('body');
    expect($response->json('body'))->toHaveCount(3);
});

test('test_get_single_room_type', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->getJson("/api/room-types/{$roomType->id}", [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('body.id', $roomType->id);
});

test('test_update_room_type_fields', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 80.00]);

    $response = $this->putJson("/api/room-types/{$roomType->id}", [
        'base_price' => 95.00,
    ], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('body.base_price', '95.00');
});

test('test_soft_delete_room_type', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->deleteJson("/api/room-types/{$roomType->id}", [], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    expect(RoomType::find($roomType->id))->toBeNull();
});

test('test_soft_deleted_not_in_list', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $this->deleteJson("/api/room-types/{$roomType->id}", [], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    expect($response->json('body'))->toHaveCount(0);
});

test('test_create_with_invalid_capacity_sum', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->postJson('/api/room-types', [
        'name' => 'Invalid Room',
        'max_occupancy' => 2,
        'adult_capacity' => 2,
        'child_capacity' => 1,
        'base_price' => 50.00,
    ], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(422);
});

test('test_create_with_zero_adult_capacity', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->postJson('/api/room-types', [
        'name' => 'Invalid Room',
        'max_occupancy' => 1,
        'adult_capacity' => 0,
        'child_capacity' => 1,
        'base_price' => 50.00,
    ], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(422);
});

test('test_delete_blocked_by_active_rooms', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);

    $response = $this->deleteJson("/api/room-types/{$roomType->id}", [], [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('body.error', 'deletion_blocked_by_rooms');
});
