<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function roomApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithOwnHotel(): array
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

function roomFor(Hotel $hotel, array $overrides = []): Room
{
    return Room::create(array_merge([
        'hotel_id' => $hotel->id,
        'room_number' => '101',
        'room_type' => 'double',
        'floor' => '1',
        'status' => 'available',
    ], $overrides));
}

// index

it('rejects an unauthenticated index request', function () {
    $this->withHeaders(roomApiHeaders())->getJson('/api/room')
        ->assertStatus(401);
});

it('rejects a non-admin user from listing rooms', function () {
    $worker = User::factory()->role(UserRole::WORKER)->create();

    $this->withHeaders(roomApiHeaders())->actingAs($worker, 'sanctum')
        ->getJson('/api/room')
        ->assertStatus(403);
});

it('only lists rooms belonging to the admin own hotel', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $mine = roomFor($hotel);

    [, $otherHotel] = adminWithOwnHotel();
    roomFor($otherHotel);

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/room');

    $response->assertOk();
    $ids = collect($response->json('body.data'))->pluck('id');
    expect($ids)->toHaveCount(1);
    expect($ids)->toContain($mine->id);
});

// store

it('creates a room for the admin hotel with valid data', function () {
    [$admin, $hotel] = adminWithOwnHotel();

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room', [
            'hotel_id' => $hotel->id,
            'room_number' => '201',
            'room_type' => 'suite',
            'floor' => '2',
            'status' => 'available',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.room_number', '201');
    expect(Room::where('room_number', '201')->where('hotel_id', $hotel->id)->exists())->toBeTrue();
});

it('rejects a room missing the required schema-derived hotel_id', function () {
    [$admin] = adminWithOwnHotel();

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room', [
            'room_number' => '201',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['hotel_id']);
});

it('rejects a non-admin user from creating a room', function () {
    $worker = User::factory()->role(UserRole::WORKER)->create();
    $hotel = Hotel::create(['owner_id' => $worker->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $this->withHeaders(roomApiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/room', ['hotel_id' => $hotel->id])
        ->assertStatus(403);
});

it('rejects creating a room for a hotel the admin does not own', function () {
    [$admin] = adminWithOwnHotel();
    [, $otherHotel] = adminWithOwnHotel();

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room', [
            'hotel_id' => $otherHotel->id,
            'room_number' => '999',
        ]);

    $response->assertStatus(403)->assertJsonPath('message', 'The selected hotel does not belong to you.');
    expect(Room::where('room_number', '999')->exists())->toBeFalse();
});

// show

it('lets an admin view a room belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/room/{$room->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $room->id);
});

it('rejects an admin viewing a room belonging to a different hotel', function () {
    [$admin] = adminWithOwnHotel();
    [, $otherHotel] = adminWithOwnHotel();
    $room = roomFor($otherHotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/room/{$room->id}")
        ->assertStatus(403);
});

// update

it('lets an admin partially update a room belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", [
            'status' => 'maintenance',
        ]);

    $response->assertOk()->assertJsonPath('body.status', 'maintenance');
    expect($room->fresh()->room_number)->toBe('101');
});

it('rejects an admin updating a room belonging to a different hotel', function () {
    [$admin] = adminWithOwnHotel();
    [, $otherHotel] = adminWithOwnHotel();
    $room = roomFor($otherHotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['status' => 'maintenance'])
        ->assertStatus(403);

    expect($room->fresh()->status)->toBe('available');
});

it('rejects an unknown hotel_id when updating a room', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['hotel_id' => 'not-a-real-uuid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hotel_id']);
});

// destroy

it('lets an admin delete a room belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room/{$room->id}")
        ->assertOk();

    expect(Room::find($room->id))->toBeNull();
});

it('rejects an admin deleting a room belonging to a different hotel', function () {
    [$admin] = adminWithOwnHotel();
    [, $otherHotel] = adminWithOwnHotel();
    $room = roomFor($otherHotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/room/{$room->id}")
        ->assertStatus(403);

    expect(Room::find($room->id))->not->toBeNull();
});

// super admin bypass

it('lets a super admin view a room belonging to any hotel', function () {
    [, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(roomApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/room/{$room->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $room->id);
});

it('lets a super admin update a room belonging to any hotel', function () {
    [, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(roomApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['status' => 'maintenance'])
        ->assertOk()
        ->assertJsonPath('body.status', 'maintenance');
});

it('lets a super admin delete a room belonging to any hotel', function () {
    [, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(roomApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/room/{$room->id}")
        ->assertOk();

    expect(Room::find($room->id))->toBeNull();
});
