<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function roomTypePermissionHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

/**
 * An employee whose staff role grants exactly the given permissions.
 *
 * @param  list<Permission>  $permissions
 */
function roomTypeEmployee(Hotel $hotel, array $permissions): User
{
    $role = StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create([
        'hotel_id' => $hotel->id,
        'staff_role_id' => $role->id,
    ]);
}

test('test_create_requires_room_types_create_permission', function () {
    $hotel = Hotel::factory()->create();
    $allowed = roomTypeEmployee($hotel, [Permission::ROOM_TYPES_CREATE]);
    $denied = roomTypeEmployee($hotel, []);

    $payload = [
        'hotel_id' => $hotel->id,
        'name' => 'Test Room',
        'max_occupancy' => 2,
        'adult_capacity' => 1,
        'child_capacity' => 1,
        'base_price' => 80.00,
    ];

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($denied, 'sanctum')
        ->postJson('/api/room-types', $payload)
        ->assertStatus(403);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($allowed, 'sanctum')
        ->postJson('/api/room-types', $payload)
        ->assertStatus(201);
});

test('test_view_requires_room_types_view_permission', function () {
    $hotel = Hotel::factory()->create();
    $allowed = roomTypeEmployee($hotel, [Permission::ROOM_TYPES_VIEW]);
    $denied = roomTypeEmployee($hotel, []);
    RoomType::factory()->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($denied, 'sanctum')
        ->getJson('/api/room-types')
        ->assertStatus(403);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($allowed, 'sanctum')
        ->getJson('/api/room-types')
        ->assertStatus(200);
});

test('test_update_requires_room_types_update_permission', function () {
    $hotel = Hotel::factory()->create();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $allowed = roomTypeEmployee($hotel, [Permission::ROOM_TYPES_UPDATE]);
    $denied = roomTypeEmployee($hotel, []);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($denied, 'sanctum')
        ->putJson("/api/room-types/{$roomType->id}", ['base_price' => 100.00])
        ->assertStatus(403);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($allowed, 'sanctum')
        ->putJson("/api/room-types/{$roomType->id}", ['base_price' => 100.00])
        ->assertStatus(200);
});

test('test_delete_requires_room_types_delete_permission', function () {
    $hotel = Hotel::factory()->create();
    $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
    $allowed = roomTypeEmployee($hotel, [Permission::ROOM_TYPES_DELETE]);
    $denied = roomTypeEmployee($hotel, []);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($denied, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(403);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($allowed, 'sanctum')
        ->deleteJson("/api/room-types/{$roomType->id}")
        ->assertStatus(200);
});

test('test_hotel_a_cannot_access_hotel_b_room_types', function () {
    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();
    $adminB = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotelB->id]);
    $roomTypeA = RoomType::factory()->create(['hotel_id' => $hotelA->id]);

    $asAdminB = fn () => $this->withHeaders(roomTypePermissionHeaders())->actingAs($adminB, 'sanctum');

    expect($asAdminB()->getJson("/api/room-types/{$roomTypeA->id}")->status())->toBeIn([403, 404]);
    expect($asAdminB()->putJson("/api/room-types/{$roomTypeA->id}", ['base_price' => 999])->status())->toBeIn([403, 404]);
    expect($asAdminB()->deleteJson("/api/room-types/{$roomTypeA->id}")->status())->toBeIn([403, 404]);
    expect(RoomType::withoutGlobalScopes()->find($roomTypeA->id)->deleted_at)->toBeNull();
});

test('test_tenant_isolation_on_list_endpoint', function () {
    $hotelA = Hotel::factory()->create();
    $hotelB = Hotel::factory()->create();
    $adminA = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotelA->id]);
    $adminB = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotelB->id]);
    RoomType::factory()->count(2)->create(['hotel_id' => $hotelA->id]);
    RoomType::factory()->count(3)->create(['hotel_id' => $hotelB->id]);

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($adminA, 'sanctum')
        ->getJson('/api/room-types')
        ->assertStatus(200)
        ->assertJsonCount(2, 'body.data');

    $this->withHeaders(roomTypePermissionHeaders())->actingAs($adminB, 'sanctum')
        ->getJson('/api/room-types')
        ->assertStatus(200)
        ->assertJsonCount(3, 'body.data');
});
