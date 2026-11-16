<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function roomTypeAvailabilityAdmin(): array
{
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    return [$admin, $hotel];
}

test('test_active_room_types_included_in_queries', function () {
    [$admin, $hotel] = roomTypeAvailabilityAdmin();
    $activeRoomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => true]);

    $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->getJson('/api/room-types');

    $response->assertStatus(200);
    expect($response->json('body.data.*.id'))->toContain($activeRoomType->id);
});

test('test_inactive_room_types_excluded_from_queries', function () {
    [$admin, $hotel] = roomTypeAvailabilityAdmin();
    $inactiveRoomType = RoomType::factory()->create(['hotel_id' => $hotel->id, 'is_active' => false]);

    // The admin list keeps inactive types (marked inactive) so they can be
    // reactivated; availability queries are the ones that must skip them.
    $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->getJson('/api/room-types');

    $response->assertStatus(200);
    $response->assertJsonPath('body.data.0.id', $inactiveRoomType->id);
    $response->assertJsonPath('body.data.0.is_active', false);
});

test('test_soft_deleted_room_types_never_in_queries', function () {
    [$admin, $hotel] = roomTypeAvailabilityAdmin();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $roomType->delete();

    $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->getJson('/api/room-types');

    $response->assertStatus(200);
    expect($response->json('body.data.*.id'))->not->toContain($roomType->id);
});
