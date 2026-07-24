<?php

use App\Models\Conversation;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Message;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('creates a message and conversation for a known guest phone number', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Demo Hotel',
        'slug' => 'demo-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '+201151793758',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/message', [
            'phone_number' => '+201151793758',
            'content' => 'Hello from dummy API test',
            'message_type' => 'text',
            'is_ai_generated' => false,
            'delivery_status' => 'pending',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Message created successfully.')
        ->assertJsonPath('code', 201)
        ->assertJsonPath('body.sender_type', 'guest')
        ->assertJsonPath('body.message.content', 'Hello from dummy API test')
        ->assertJsonPath('body.message.delivery_status', 'pending')
        ->assertJsonPath('body.message.conversation_id', '+201151793758')
        ->assertJsonPath('body.message.sender_id', $guest->id)
        ->assertJsonPath('body.message.sender_type', Guest::class)
        ->assertJsonPath('body.message.conversation.sender_id', $guest->id)
        ->assertJsonPath('body.message.conversation.sender_type', Guest::class)
        ->assertJsonPath('body.message.conversation.hotel_id', $hotel->id)
        ->assertJsonPath('body.message.conversation.id', '+201151793758')
        ->assertJsonPath('body.message.sender.id', $guest->id);

    expect(Message::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
});

it('resolves the guest hotel through their current reservation instead of their stored hotel_id', function () {
    $owner = User::factory()->create();
    $staleHotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Stale Hotel',
        'slug' => 'stale-hotel',
        'currency' => 'USD',
    ]);
    $currentHotel = Hotel::create([
        'owner_id' => User::factory()->create()->id,
        'name' => 'Current Hotel',
        'slug' => 'current-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $staleHotel->id,
        'phone_number' => '+201151793758',
    ]);
    Reservation::create([
        'hotel_id' => $currentHotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/message', [
            'phone_number' => '+201151793758',
            'content' => 'Is breakfast included?',
            'message_type' => 'text',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.message.conversation.hotel_id', $currentHotel->id);
});

it('recognizes a phone number belonging to a user as an admin sender', function () {
    $user = User::factory()->create([
        'phone_number' => '+201151793758',
    ]);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '+201151793758',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/message', [
            'phone_number' => '+201151793758',
            'content' => 'Hello from dummy API test',
            'message_type' => 'text',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.sender_type', 'admin')
        ->assertJsonPath('body.message.sender_id', $user->id)
        ->assertJsonPath('body.message.sender_type', User::class)
        ->assertJsonPath('body.message.conversation.hotel_id', $hotel->id);

    expect(Message::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
});

it('stores a message with no sender entity for an unrecognized phone number', function () {
    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/message', [
            'phone_number' => '+201151793758',
            'content' => 'Hello from dummy API test',
            'message_type' => 'text',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.sender_type', 'unknown')
        ->assertJsonPath('body.message.sender_id', null)
        ->assertJsonPath('body.message.sender_type', null)
        ->assertJsonPath('body.message.conversation.sender_id', null)
        ->assertJsonPath('body.message.conversation.hotel_id', null);

    expect(Message::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
    expect(Guest::count())->toBe(0);
});
