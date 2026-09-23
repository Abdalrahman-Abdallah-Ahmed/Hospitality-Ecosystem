<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
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
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101', 'status' => 'occupied']);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '102', 'status' => 'available']);

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
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '102']);
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

// VIP guests

function dashboardGuestWithStay(Hotel $hotel, bool $isVip, string $status, string $arrivalDate, ?Room $room = null): Guest
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Guest '.uniqid(), 'is_vip' => $isVip]);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'room_id' => $room?->id,
        'planned_arrival_date' => $arrivalDate,
        'planned_departure_date' => now()->addDays(5)->toDateString(),
        'status' => $status,
    ]);

    return $guest;
}

it('lists VIP guests who are in house or arriving today', function () {
    [$admin, $hotel] = userWithOwnHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $inHouse = dashboardGuestWithStay($hotel, true, 'in_house', now()->subDay()->toDateString(), $room);
    $arrivingToday = dashboardGuestWithStay($hotel, true, 'expected', now()->toDateString());
    dashboardGuestWithStay($hotel, true, 'expected', now()->addDay()->toDateString());
    dashboardGuestWithStay($hotel, true, 'departed', now()->subDays(3)->toDateString());
    dashboardGuestWithStay($hotel, false, 'in_house', now()->subDay()->toDateString());

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk();

    $vipGuests = collect($response->json('body.vip_guests'))->keyBy('id');

    expect($response->json('body.vip_guests_count'))->toBe(2)
        ->and($vipGuests->keys()->all())->toEqualCanonicalizing([$inHouse->id, $arrivingToday->id])
        ->and($vipGuests[$inHouse->id]['stays'][0]['room']['room_number'])->toBe('101')
        ->and($vipGuests[$inHouse->id]['stays'][0]['room']['room_type']['name'])->toBe(RoomType::DEFAULT_NAME)
        ->and($vipGuests[$arrivingToday->id]['stays'][0]['status'])->toBe('expected');
});

it('embeds the room type on today\'s arrivals and departures', function () {
    [$admin, $hotel] = userWithOwnHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Ann']);

    $reservation = fn (string $id, $arrival, $departure) => createReservationWithRooms($hotel, [['room_id' => $room->id]], [
        'guest_id' => $guest->id,
        'reservation_id' => $id,
        'arrival_date' => $arrival->toDateString(),
        'departure_date' => $departure->toDateString(),
    ]);
    $reservation('RES-ARRIVING', now(), now()->addDays(2));
    $reservation('RES-DEPARTING', now()->subDays(2), now());

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk();

    expect($response->json('body.today_arrivals.0.rooms.0.room_type.name'))->toBe(RoomType::DEFAULT_NAME)
        ->and($response->json('body.today_arrivals.0.rooms.0.room.room_number'))->toBe('101')
        ->and($response->json('body.today_departures.0.rooms.0.room_type.name'))->toBe(RoomType::DEFAULT_NAME);
});

it('leaves guest contact details out of the dashboard VIP list', function () {
    [$admin, $hotel] = userWithOwnHotel();
    $guest = dashboardGuestWithStay($hotel, true, 'in_house', now()->subDay()->toDateString());
    $guest->update(['email' => 'vip@example.test', 'phone_number' => '201000000001']);

    $vipGuest = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('body.vip_guests.0');

    expect($vipGuest['id'])->toBe($guest->id)
        ->and($vipGuest)->not->toHaveKey('email')
        ->and($vipGuest)->not->toHaveKey('phone_number');
});

it('never lists another hotel\'s VIP guests on the dashboard', function () {
    [$admin, $hotel] = userWithOwnHotel();
    [, $otherHotel] = userWithOwnHotel();
    $ownVip = dashboardGuestWithStay($hotel, true, 'in_house', now()->subDay()->toDateString());
    dashboardGuestWithStay($otherHotel, true, 'in_house', now()->subDay()->toDateString());

    $response = $this->withHeaders(dashboardApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk();

    expect($response->json('body.vip_guests.*.id'))->toBe([$ownVip->id]);
});
