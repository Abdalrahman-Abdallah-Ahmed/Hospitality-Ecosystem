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

function dashboardApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function userWithOwnHotel(): array
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

it('rejects a user with no associated hotel', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(dashboardApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertStatus(403);
});

it('no longer returns a field named revenue_today', function () {
    [$admin, $hotel] = userWithOwnHotel();

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard');

    $response->assertOk();
    $body = $response->json('body');

    expect($body)->not->toHaveKey('revenue_today');
    expect($body)->not->toHaveKey('occupancy_percentage');
    expect($body)->toHaveKey('booking_value_today');
});

it('reports today\'s occupancy from the current room-status snapshot', function () {
    [$admin, $hotel] = userWithOwnHotel();
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '101', 'status' => 'occupied']);
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '102', 'status' => 'available']);

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard');

    $response->assertOk();
    $occupancy = $response->json('body.occupancy');

    expect($occupancy['occupied_rooms'])->toBe(1);
    expect($occupancy['total_rooms'])->toBe(2);
    expect($occupancy['percentage'])->toEqual(50.0);
    expect($occupancy['historical_supported'])->toBeFalse();
});

it('honestly returns null occupancy for a non-today date instead of a wrong number', function () {
    [$admin, $hotel] = userWithOwnHotel();
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '101', 'status' => 'occupied']);

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard?date=2020-01-01');

    $response->assertOk();
    $occupancy = $response->json('body.occupancy');

    expect($occupancy['date'])->toBe('2020-01-01');
    expect($occupancy['occupied_rooms'])->toBeNull();
    expect($occupancy['percentage'])->toBeNull();
});
