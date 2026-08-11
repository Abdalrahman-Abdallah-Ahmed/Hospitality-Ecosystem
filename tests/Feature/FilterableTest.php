<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

// scopeFilter / scopeSearch, called directly on the model

it('filters a model by an exact column match', function () {
    $hotel = Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-1', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'confirmed']);
    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-2', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'pending']);

    $confirmed = Reservation::filter(['status' => 'confirmed'])->get();

    expect($confirmed)->toHaveCount(1);
    expect($confirmed->first()->reservation_id)->toBe('RES-1');
});

it('filters a model with an array value using whereIn', function () {
    $hotel = Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-1', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'confirmed']);
    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-2', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'pending']);
    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-3', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'cancelled']);

    $matches = Reservation::filter(['status' => ['confirmed', 'pending']])->get();

    expect($matches)->toHaveCount(2);
});

it('silently ignores a filter key that is not a real column', function () {
    $hotel = Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $results = Hotel::filter(['not_a_real_column' => 'anything'])->get();

    expect($results)->toHaveCount(1);
});

it('searches a model text columns for a term', function () {
    Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Grand Harbor Hotel', 'slug' => 'grand-harbor', 'currency' => 'USD']);
    Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Seaside Resort', 'slug' => 'seaside-resort', 'currency' => 'USD']);

    $results = Hotel::search('Harbor')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Grand Harbor Hotel');
});

it('returns everything when the search term is empty', function () {
    Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Grand Harbor Hotel', 'slug' => 'grand-harbor', 'currency' => 'USD']);
    Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Seaside Resort', 'slug' => 'seaside-resort', 'currency' => 'USD']);

    expect(Hotel::search(null)->get())->toHaveCount(2);
    expect(Hotel::search('')->get())->toHaveCount(2);
});

// end-to-end through the generic index endpoints

it('filters reservations by status through the index endpoint', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['owner_id' => $admin->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $admin->update(['hotel_id' => $hotel->id]);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);

    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-1', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'checked_in']);
    Reservation::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-2', 'arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04', 'status' => 'pending']);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($admin, 'sanctum')
        ->getJson('/api/reservation?filter[status]=checked_in');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['reservation_id'])->toBe('RES-1');
});

it('rejects an unknown filter column on the index endpoint', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    Hotel::create(['owner_id' => $admin->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($admin, 'sanctum')
        ->getJson('/api/reservation?filter[not_a_column]=x');

    $response->assertStatus(422)->assertJsonValidationErrors(['filter.not_a_column']);
});

it('searches hotels by name through the index endpoint', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    Hotel::create(['owner_id' => $admin->id, 'name' => 'Grand Harbor Hotel', 'slug' => 'grand-harbor', 'currency' => 'USD']);
    Hotel::create(['owner_id' => $admin->id, 'name' => 'Seaside Resort', 'slug' => 'seaside-resort', 'currency' => 'USD']);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/hotel?search=Harbor');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['name'])->toBe('Grand Harbor Hotel');
});
