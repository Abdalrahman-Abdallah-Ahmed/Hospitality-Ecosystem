<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
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
    expect($occupancy['basis'])->toBe('room_status_snapshot');
    expect($occupancy['historical_supported'])->toBeTrue();
});

it('computes occupancy for a non-today date from stay events', function () {
    [$admin, $hotel] = userWithOwnHotel();
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '102']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    // In house across the requested date.
    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2026-01-01',
        'planned_departure_date' => '2026-01-05',
        'status' => 'in_house',
    ]);

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard?date=2026-01-03');

    $response->assertOk();
    $occupancy = $response->json('body.occupancy');

    expect($occupancy['date'])->toBe('2026-01-03');
    expect($occupancy['occupied_rooms'])->toBe(1);
    expect($occupancy['total_rooms'])->toBe(2);
    expect($occupancy['basis'])->toBe('stay_events');
    expect($occupancy['historical_supported'])->toBeTrue();
});

it('sums room_revenue_today from stays currently in house', function () {
    [$admin, $hotel] = userWithOwnHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => now()->toDateString(),
        'planned_departure_date' => now()->addDays(3)->toDateString(),
        'status' => 'in_house',
        'room_revenue' => 300,
    ]);

    // Not in house — must not count.
    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => now()->toDateString(),
        'planned_departure_date' => now()->addDays(3)->toDateString(),
        'status' => 'expected',
        'room_revenue' => 500,
    ]);

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard');

    $response->assertOk();
    expect((float) $response->json('body.room_revenue_today'))->toBe(300.0);
});
