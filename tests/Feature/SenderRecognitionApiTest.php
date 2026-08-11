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

it('identifies a user phone number as an admin', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '+201151793758',
    ]);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp/identify', [
            'phone_number' => '+201151793758',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.sender_type', 'admin')
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.sender.id', $user->id)
        ->assertJsonPath('body.reservation', null);
});

it('identifies a guest phone number and resolves the hotel through their current reservation', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Seaside Hotel',
        'slug' => 'seaside-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '+201151793758',
    ]);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp/identify', [
            'phone_number' => '+201151793758',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.sender_type', 'guest')
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.sender.id', $guest->id)
        ->assertJsonPath('body.reservation.id', $reservation->id);
});

it('identifies an unrecognized phone number as unknown', function () {
    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp/identify', [
            'phone_number' => '+201151793758',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.sender_type', 'unknown')
        ->assertJsonPath('body.hotel_id', null)
        ->assertJsonPath('body.sender', null)
        ->assertJsonPath('body.reservation', null);
});

it('rejects requests without a valid api key', function () {
    $response = $this->postJson('/api/whatsapp/identify', [
        'phone_number' => '+201151793758',
    ]);

    $response->assertStatus(401);
});
