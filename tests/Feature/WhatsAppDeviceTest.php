<?php

use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('creates a whatsapp device token for an authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/connect');

    $response->assertOk()
        ->assertJsonPath('message', 'WhatsApp device token created successfully.')
        ->assertJsonPath('code', 200);

    expect($response->json('body.token'))->toBeString()->not->toBe('');
    expect($user->tokens()->where('name', 'whatsapp_device_token')->exists())->toBeTrue();
});

it('pairs a whatsapp device for a user identified by a request-body token', function () {
    $user = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Demo Hotel',
        'slug' => 'demo-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);
    $token = $user->createToken('whatsapp_device_token')->plainTextToken;

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', [
            'phone_number' => '201151793758',
            'wa_user_id' => 'EG.1586110233134033',
            'token' => $token,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'WhatsApp device paired successfully.')
        ->assertJsonPath('code', 200)
        ->assertJsonPath('body.user_id', $user->id)
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.phone_number', '201151793758')
        ->assertJsonPath('body.wa_user_id', 'EG.1586110233134033')
        ->assertJsonPath('body.status', 'active');

    expect(WhatsAppDevice::where('user_id', $user->id)->exists())->toBeTrue();
});

it('rejects pairing when the token is invalid', function () {
    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', [
            'phone_number' => '201151793758',
            'wa_user_id' => 'EG.1586110233134033',
            'token' => 'invalid-token',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Invalid token.')
        ->assertJsonPath('code', 201)
        ->assertJsonPath('body', null);
});

it('rejects pairing when the token owner has no hotel', function () {
    $user = User::factory()->create();
    $token = $user->createToken('whatsapp_device_token')->plainTextToken;

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', [
            'phone_number' => '201151793758',
            'wa_user_id' => 'EG.1586110233134033',
            'token' => $token,
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('message', 'User is not associated with any hotel.')
        ->assertJsonPath('code', 202)
        ->assertJsonPath('body', null);
});

it('rejects pairing when the user already has a paired whatsapp device', function () {
    $user = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Demo Hotel',
        'slug' => 'demo-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);
    $token = $user->createToken('whatsapp_device_token')->plainTextToken;

    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/pair', [
            'phone_number' => '201199999999',
            'wa_user_id' => 'EG.9999999999999999',
            'token' => $token,
        ]);

    $response->assertStatus(203)
        ->assertJsonPath('message', 'User already has a paired WhatsApp device.')
        ->assertJsonPath('code', 203)
        ->assertJsonPath('body', null);

    expect(WhatsAppDevice::count())->toBe(1);
});
