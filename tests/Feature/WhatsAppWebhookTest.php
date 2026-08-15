<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Models\Conversation;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.whatsapp.verify_token' => 'test-verify-token',
        'services.whatsapp.app_secret' => 'test-app-secret',
        'services.whatsapp.phone_number_id' => 'test-phone-number-id',
        'services.whatsapp.access_token' => 'test-access-token',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
});

function whatsappInboundPayload(string $from, string $text): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => $from,
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

function whatsappSignatureHeader(array $payload): array
{
    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-app-secret');

    return ['X-Hub-Signature-256' => $signature];
}

// verify handshake

it('echoes the challenge when the verify token matches', function () {
    $this->getJson('/api/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=12345')
        ->assertOk()
        ->assertSee('12345');
});

it('rejects the verify handshake when the token does not match', function () {
    $this->getJson('/api/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
        ->assertStatus(403);
});

// signature verification

it('rejects an inbound webhook with a missing signature', function () {
    $this->postJson('/api/whatsapp', whatsappInboundPayload('201151793758', 'Hello'))
        ->assertStatus(403);
});

it('rejects an inbound webhook with an invalid signature', function () {
    $this->postJson('/api/whatsapp', whatsappInboundPayload('201151793758', 'Hello'), [
        'X-Hub-Signature-256' => 'sha256=not-the-right-signature',
    ])->assertStatus(403);
});

// guest flow

it('replies to a recognized guest and persists the conversation', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Seaside Hotel',
        'slug' => 'seaside-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '201151793758',
    ]);

    GuestConciergeAgent::fake(['Sure, happy to help!']);

    $payload = whatsappInboundPayload('201151793758', 'What time is checkout?');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && $request['to'] === '201151793758'
        && $request['text']['body'] === 'Sure, happy to help!');

    expect(Conversation::where('participant_id', $guest->id)->count())->toBe(1);
});

// admin flow

it('replies via the admin advisor when the admin has an active paired device', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create(['phone_number' => '201151793758']);
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);
    WhatsAppDevice::create([
        'user_id' => $admin->id,
        'phone_number' => '201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    AdminAdvisorAgent::fake(['Today you have 3 arrivals.']);

    $payload = whatsappInboundPayload('201151793758', 'Any reservations today?');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    Http::assertSent(fn ($request) => $request['text']['body'] === 'Today you have 3 arrivals.');
    expect(Conversation::where('participant_id', $admin->id)->count())->toBe(1);
});

it('sends a pairing-guidance reply and never invokes the advisor for an unpaired admin', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create(['phone_number' => '201151793758']);
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    AdminAdvisorAgent::fake();

    $payload = whatsappInboundPayload('201151793758', 'Any reservations today?');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['text']['body'], 'not yet paired'));
    AdminAdvisorAgent::assertNotPrompted(fn () => true);
});

// unknown sender

it('sends a fallback reply for an unrecognized phone number', function () {
    $payload = whatsappInboundPayload('201151793758', 'Hello?');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['text']['body'], "couldn't recognize"));
});

// pairing via WhatsApp message

it('pairs a device when an admin sends their connect() token as a message', function () {
    $admin = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);
    $token = $admin->createToken('whatsapp_device_token')->plainTextToken;

    $payload = whatsappInboundPayload('201151793758', $token);
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    expect(WhatsAppDevice::where('user_id', $admin->id)->where('phone_number', '201151793758')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request['text']['body'], 'all set'));
});

it('replies with guidance when the pairing token is invalid', function () {
    $token = User::factory()->create()->createToken('whatsapp_device_token')->plainTextToken;
    [$tokenId] = explode('|', $token, 2);
    $invalidToken = $tokenId.'|not-the-right-plaintext-and-its-forty-chars-long';

    $payload = whatsappInboundPayload('201151793758', $invalidToken);
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['text']['body'], "isn't valid"));
    expect(WhatsAppDevice::count())->toBe(0);
});

it('does not treat an ordinary chat message as a pairing attempt', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Seaside Hotel',
        'slug' => 'seaside-hotel',
        'currency' => 'USD',
    ]);
    Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '201151793758']);

    GuestConciergeAgent::fake(['Hi there!']);

    $payload = whatsappInboundPayload('201151793758', 'Hello, is the pool open?');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    expect(WhatsAppDevice::count())->toBe(0);
    Http::assertSent(fn ($request) => $request['text']['body'] === 'Hi there!');
});
