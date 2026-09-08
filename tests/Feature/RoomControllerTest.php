<?php

use App\Enums\HousekeepingStatusesEnum;
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
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
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
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

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
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();
    $hotel = Hotel::create(['owner_id' => $worker->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $this->withHeaders(roomApiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/room', ['hotel_id' => $hotel->id])
        ->assertStatus(403);
});

it('creates a room scoped to the caller own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    [, $otherHotel] = adminWithOwnHotel();

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room', [
            'hotel_id' => $otherHotel->id,
            'room_number' => '999',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);

    expect(Room::where('hotel_id', $otherHotel->id)->where('room_number', '999')->exists())->toBeFalse();
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

    expect(Room::withoutGlobalScope('hotel')->find($room->id))->not->toBeNull();
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

// housekeeping status

it('defaults a newly created room to clean', function () {
    [$admin, $hotel] = adminWithOwnHotel();

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/room', [
            'hotel_id' => $hotel->id,
            'room_number' => '204',
            'room_type' => 'double',
            'floor' => '2',
        ])
        ->assertStatus(201);

    // The column default fills this in, so the caller never has to send it.
    expect($hotel->rooms()->where('room_number', '204')->first()->housekeeping_status)
        ->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('lets an admin flag a room as dirty or blocked', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['housekeeping_status' => 'dirty'])
        ->assertOk()
        ->assertJsonPath('body.housekeeping_status', 'dirty');

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['housekeeping_status' => 'blocked'])
        ->assertOk();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::BLOCKED);
});

it('rejects a housekeeping status outside the allowed set', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    $room = roomFor($hotel);

    $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/room/{$room->id}", ['housekeeping_status' => 'sparkling'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['housekeeping_status']);
});

it('filters the room list by housekeeping status', function () {
    [$admin, $hotel] = adminWithOwnHotel();
    roomFor($hotel, ['room_number' => '101']);
    roomFor($hotel, ['room_number' => '102', 'housekeeping_status' => 'dirty']);
    roomFor($hotel, ['room_number' => '103', 'housekeeping_status' => 'blocked']);

    $response = $this->withHeaders(roomApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/room?filter[housekeeping_status]=dirty')
        ->assertOk();

    expect($response->json('body.data'))->toHaveCount(1)
        ->and($response->json('body.data.0.room_number'))->toBe('102');
});
