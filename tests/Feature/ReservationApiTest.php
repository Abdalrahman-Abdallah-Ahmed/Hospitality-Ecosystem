<?php

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function pairedDevice(): array
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $device = WhatsAppDevice::create([
        'user_id' => $owner->id,
        'phone_number' => '201000000001',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'wa-test-instance',
        'status' => 'active',
    ]);

    return [$hotel, $device];
}

it('creates a guest and reservation from AI-extracted screenshot data', function () {
    [$hotel] = pairedDevice();

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'guest' => [
                'first_name' => 'Youssef',
                'last_name' => 'Kamal',
                'phone_number' => '201222333444',
                'email' => 'youssef.kamal@example.test',
            ],
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
            'status' => 'confirmed',
            'reservation_value' => 500.00,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.source', 'booking_com')
        ->assertJsonPath('body.guest.external_id', 'booking-ext-98231')
        ->assertJsonPath('body.guest.channel', 'booking_com')
        ->assertJsonPath('body.guest.first_name', 'Youssef');

    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(1);
});

it('reuses the existing guest for the same external id and channel', function () {
    pairedDevice();

    $first = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $second = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'arrival_date' => '2026-11-01',
            'departure_date' => '2026-11-03',
        ]);

    expect($first->json('body.guest.id'))->toBe($second->json('body.guest.id'));
    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(2);
});

it('creates a separate guest for the same external id on a different channel', function () {
    pairedDevice();

    $this->withHeader('X-API-KEY', 'test-api-key')->postJson('/api/whatsapp-reservation', [
        'phone_number' => '201000000001',
        'guest_id' => 'shared-id-1',
        'channel' => 'booking_com',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]);

    $this->withHeader('X-API-KEY', 'test-api-key')->postJson('/api/whatsapp-reservation', [
        'phone_number' => '201000000001',
        'guest_id' => 'shared-id-1',
        'channel' => 'airbnb',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]);

    expect(Guest::count())->toBe(2);
});

it('rejects the request when the whatsapp device is not paired', function () {
    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'WhatsApp device not paired.');

    expect(Guest::count())->toBe(0);
    expect(Reservation::count())->toBe(0);
});

it('rejects the request when the whatsapp device is paired but inactive', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    WhatsAppDevice::create([
        'user_id' => $owner->id,
        'phone_number' => '201000000001',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'wa-test-instance',
        'status' => 'pending',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'WhatsApp device not paired.');
});

it('rejects a room that does not belong to the resolved hotel', function () {
    pairedDevice();

    $otherOwner = User::factory()->create();
    $otherHotel = Hotel::create([
        'owner_id' => $otherOwner->id,
        'name' => 'Other Hotel',
        'slug' => 'other-hotel',
        'currency' => 'USD',
    ]);
    $foreignRoom = Room::create([
        'hotel_id' => $otherHotel->id,
        'room_number' => '101',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/whatsapp-reservation', [
            'phone_number' => '201000000001',
            'guest_id' => 'booking-ext-98231',
            'channel' => 'booking_com',
            'room_number' => $foreignRoom->room_number,
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'The selected room does not belong to this hotel.');

    expect(Reservation::count())->toBe(0);
});
