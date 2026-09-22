<?php

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class)->beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

test('test_active_room_types_included_in_queries', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $activeRoomType = RoomType::factory()->create([
        'hotel_id' => $hotel->id,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('body'))->pluck('id')->toArray();
    expect($ids)->toContain($activeRoomType->id);
});

test('test_inactive_room_types_excluded_from_queries', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $inactiveRoomType = RoomType::factory()->create([
        'hotel_id' => $hotel->id,
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('body.0.is_active', true);
});

test('test_soft_deleted_room_types_never_in_queries', function () {
    $hotel = Hotel::factory()->create();
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $roomType->delete();

    $response = $this->getJson('/api/room-types', [
        'Authorization' => "Bearer {$user->createToken('test')->plainTextToken}",
        'X-API-KEY' => 'test-api-key',
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('body'))->pluck('id')->toArray();
    expect($ids)->not->toContain($roomType->id);
});
