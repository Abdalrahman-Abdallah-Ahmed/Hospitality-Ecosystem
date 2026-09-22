<?php

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class)->beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

test('test_create_requires_room_types_create_permission', function () {
    $hotel = Hotel::factory()->create();
    $userWithPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'admin']);
    $userWithoutPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'employee']);

    $payload = [
        'name' => 'Test Room',
        'max_occupancy' => 2,
        'adult_capacity' => 1,
        'child_capacity' => 1,
        'base_price' => 80.00,
    ];

    $response = $this->postJson('/api/room-types', $payload, [
        'Authorization' => "Bearer {$userWithoutPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(403);

    $response = $this->postJson('/api/room-types', $payload, [
        'Authorization' => "Bearer {$userWithPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(201);
});

test('test_view_requires_room_types_view_permission', function () {
    $hotel = Hotel::factory()->create();
    $userWithPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'admin']);
    $userWithoutPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'employee']);
    RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$userWithoutPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(403);

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$userWithPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(200);
});

test('test_update_requires_room_types_update_permission', function () {
    $hotel = Hotel::factory()->create();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $userWithPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'admin']);
    $userWithoutPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'employee']);

    $payload = ['base_price' => 100.00];

    $response = $this->putJson("/api/room-types/{$roomType->id}", $payload, [
        'Authorization' => "Bearer {$userWithoutPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(403);

    $response = $this->putJson("/api/room-types/{$roomType->id}", $payload, [
        'Authorization' => "Bearer {$userWithPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(200);
});

test('test_delete_requires_room_types_delete_permission', function () {
    $hotel = Hotel::factory()->create();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $userWithPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'admin']);
    $userWithoutPerm = User::factory()->create(['hotel_id' => $hotel->id, 'role' => 'employee']);

    $response = $this->deleteJson("/api/room-types/{$roomType->id}", [], [
        'Authorization' => "Bearer {$userWithoutPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(403);

    $roomTypeForDelete = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $response = $this->deleteJson("/api/room-types/{$roomTypeForDelete->id}", [], [
        'Authorization' => "Bearer {$userWithPerm->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $response->assertStatus(200);
});

test('test_hotel_a_cannot_access_hotel_b_room_types', function () {
    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();
    $userA = User::factory()->create(['hotel_id' => $hotelA->id, 'role' => 'admin']);
    $userB = User::factory()->create(['hotel_id' => $hotelB->id, 'role' => 'admin']);
    $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);

    $response = $this->getJson("/api/room-types/{$roomTypeA->id}", [
        'Authorization' => "Bearer {$userB->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    expect($response->status())->toBeIn([403, 404]);

    $response = $this->putJson("/api/room-types/{$roomTypeA->id}", ['base_price' => 999], [
        'Authorization' => "Bearer {$userB->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    expect($response->status())->toBeIn([403, 404]);

    $response = $this->deleteJson("/api/room-types/{$roomTypeA->id}", [], [
        'Authorization' => "Bearer {$userB->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    expect($response->status())->toBeIn([403, 404]);
});

test('test_tenant_isolation_on_list_endpoint', function () {
    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();
    $userA = User::factory()->create(['hotel_id' => $hotelA->id, 'role' => 'admin']);
    $userB = User::factory()->create(['hotel_id' => $hotelB->id, 'role' => 'admin']);
    RoomType::factory()->count(2)->create(['hotel_id' => $hotelA->id]);
    RoomType::factory()->count(3)->create(['hotel_id' => $hotelB->id]);

    $responseA = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$userA->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $responseA->assertStatus(200);
    expect($responseA->json('body'))->toHaveCount(2);

    $responseB = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$userB->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);
    $responseB->assertStatus(200);
    expect($responseB->json('body'))->toHaveCount(3);
});
