<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\InboundMessageStatus;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.whatsapp.verify_token' => 'test-verify-token',
        'services.whatsapp.app_secret' => 'test-app-secret',
        'services.whatsapp.phone_number_id' => 'test-phone-number-id',
        'services.whatsapp.access_token' => 'test-access-token',
    ]);
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
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

function whatsappInboundImagePayload(string $from, string $mediaId, string $caption = ''): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => $from,
                        'type' => 'image',
                        'image' => ['id' => $mediaId, 'mime_type' => 'image/jpeg', 'caption' => $caption],
                    ]],
                ],
            ]],
        ]],
    ];
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

it('extracts reservation details from an admin screenshot', function () {
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

    Http::fake([
        'graph.facebook.com/*/media-123' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-123', 'mime_type' => 'image/jpeg'], 200),
        'lookaside.fbsbx.com/*' => Http::response('fake-image-bytes', 200),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
    ]);

    AdminAdvisorAgent::fake(['Created the reservation for John Doe.']);

    $payload = whatsappInboundImagePayload('201151793758', 'media-123');
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    AdminAdvisorAgent::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1
        && $prompt->attachments->first()->mime === 'image/jpeg'
        && $prompt->attachments->first()->base64 === base64_encode('fake-image-bytes'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
        && $request['text']['body'] === 'Created the reservation for John Doe.');
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
    $token = pairingCodeFor($admin);

    $payload = whatsappInboundPayload('201151793758', $token);
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))
        ->assertOk();

    expect(WhatsAppDevice::where('user_id', $admin->id)->where('phone_number', '201151793758')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request['text']['body'], 'all set'));
});

it('replies with guidance when the pairing token is invalid', function () {
    $token = pairingCodeFor(User::factory()->create());
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

// delivery guarantees

function webhookGuestAt(string $hotelName, string $phone): Guest
{
    $hotel = Hotel::create([
        'owner_id' => User::factory()->create()->id,
        'name' => $hotelName,
        'slug' => Str::slug($hotelName).'-'.Str::lower(Str::random(6)),
        'currency' => 'USD',
    ]);

    return Guest::create(['hotel_id' => $hotel->id, 'phone_number' => $phone]);
}

function whatsappMessage(string $from, string $text, string $wamid): array
{
    return ['id' => $wamid, 'from' => $from, 'text' => ['body' => $text]];
}

function whatsappPayloadOf(array ...$messages): array
{
    return ['entry' => [['changes' => [['value' => ['messages' => $messages]]]]]];
}

function inboundJobFor(Guest $guest): ProcessInboundWhatsAppMessageJob
{
    return new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create([
            'phone_number' => $guest->phone_number,
            'status' => InboundMessageStatus::RECEIVED,
        ]),
        phoneNumber: $guest->phone_number,
        messageText: 'Hello',
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $guest->hotel,
        reservation: null,
        devicePaired: false,
    );
}

function runInboundJob(ProcessInboundWhatsAppMessageJob $job): void
{
    $job->handle(app(WhatsAppMessageService::class), app(MeteringService::class));
}

it('answers a message Meta redelivers only once', function () {
    webhookGuestAt('Seaside Hotel', '201151793758');
    GuestConciergeAgent::fake(['Checkout is at noon.', 'a second answer that must not be sent']);

    $payload = whatsappPayloadOf(whatsappMessage('201151793758', 'What time is checkout?', 'wamid.ONE'));

    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))->assertOk();
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))->assertOk();

    Http::assertSentCount(1);
    expect(WhatsAppInboundMessage::where('wamid', 'wamid.ONE')->count())->toBe(1)
        ->and(WhatsAppInboundMessage::first()->status)->toBe(InboundMessageStatus::REPLIED);
});

it('answers every message in a batched delivery', function () {
    webhookGuestAt('Seaside Hotel', '201151793758');
    webhookGuestAt('Harbor Hotel', '201000000002');
    GuestConciergeAgent::fake(['First answer', 'Second answer']);

    $payload = whatsappPayloadOf(
        whatsappMessage('201151793758', 'Is the pool open?', 'wamid.A'),
        whatsappMessage('201000000002', 'Is breakfast included?', 'wamid.B'),
    );

    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))->assertOk();

    Http::assertSent(fn ($request) => $request['to'] === '201151793758');
    Http::assertSent(fn ($request) => $request['to'] === '201000000002');
});

it('stops answering a sender past the per-minute limit and tells them once', function () {
    config(['services.whatsapp.inbound_per_minute' => 2]);
    webhookGuestAt('Seaside Hotel', '201151793758');
    $prompts = [];
    GuestConciergeAgent::fake(function (string $prompt) use (&$prompts) {
        $prompts[] = $prompt;

        return 'ok';
    });

    foreach (range(1, 4) as $i) {
        $payload = whatsappPayloadOf(whatsappMessage('201151793758', "Message {$i}", "wamid.{$i}"));
        $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))->assertOk();
    }

    // The first message is seen twice: once for the reply and once to title
    // the new conversation.
    expect(array_values(array_unique($prompts)))->toBe(['Message 1', 'Message 2'])
        ->and(WhatsAppInboundMessage::where('status', InboundMessageStatus::THROTTLED)->count())->toBe(2);
    // Two answers plus exactly one notice.
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request['text']['body'], 'faster than we can answer'));
});

it('retries a failed send without asking the model again', function () {
    $guest = webhookGuestAt('Seaside Hotel', '201151793758');
    $generated = 0;
    GuestConciergeAgent::fake(function () use (&$generated) {
        $generated++;

        return 'Here is your answer.';
    });
    $sent = [];
    $this->mock(WhatsAppMessageService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('send')->andReturnUsing(function (string $to, string $text) use (&$sent) {
            if ($sent === []) {
                $sent[] = 'failed';

                throw new RuntimeException('Graph API unavailable');
            }

            $sent[] = $text;
        });
    });

    $job = inboundJobFor($guest);

    expect(fn () => runInboundJob($job))->toThrow(RuntimeException::class);

    $modelCallsBeforeRetry = $generated;

    runInboundJob($job);

    expect($modelCallsBeforeRetry)->toBeGreaterThan(0)
        ->and($generated)->toBe($modelCallsBeforeRetry)
        ->and($sent)->toBe(['failed', 'Here is your answer.'])
        ->and($job->inbound->fresh()->replied_at)->not->toBeNull();
});

it('apologises instead of re-running a turn an earlier attempt already started', function () {
    $guest = webhookGuestAt('Seaside Hotel', '201151793758');
    GuestConciergeAgent::fake(['should not be generated']);

    $job = inboundJobFor($guest);
    $job->inbound->update(['generation_started_at' => now()->subMinute()]);

    runInboundJob($job);

    GuestConciergeAgent::assertNeverPrompted();
    Http::assertSent(fn ($request) => $request['text']['body'] === ProcessInboundWhatsAppMessageJob::FAILED_REPLY);
});

it('apologises when the agent turn fails', function () {
    $guest = webhookGuestAt('Seaside Hotel', '201151793758');
    GuestConciergeAgent::fake(fn () => throw new RuntimeException('provider down'));

    runInboundJob(inboundJobFor($guest));

    Http::assertSent(fn ($request) => $request['text']['body'] === ProcessInboundWhatsAppMessageJob::FAILED_REPLY);
});

it('runs the agent turn scoped to the sender hotel', function () {
    $guest = webhookGuestAt('Seaside Hotel', '201151793758');
    $scopeDuringTurn = 'not captured';

    GuestConciergeAgent::fake(function () use (&$scopeDuringTurn) {
        $scopeDuringTurn = TenantContext::hotelIds();

        return 'ok';
    });

    runInboundJob(inboundJobFor($guest));

    expect($scopeDuringTurn)->toBe([$guest->hotel_id])
        ->and(TenantContext::hotelIds())->toBeNull();
});

it('routes a guest known at two hotels to the one they are staying at', function () {
    $pastGuest = webhookGuestAt('Former Hotel', '201151793758');
    $currentGuest = webhookGuestAt('Current Hotel', '+20 115 179 3758');

    Reservation::create([
        'hotel_id' => $pastGuest->hotel_id,
        'guest_id' => $pastGuest->id,
        'reservation_id' => 'OLD-1',
        'arrival_date' => now()->subMonths(2),
        'departure_date' => now()->subMonths(2)->addDays(3),
    ]);
    Reservation::create([
        'hotel_id' => $currentGuest->hotel_id,
        'guest_id' => $currentGuest->id,
        'reservation_id' => 'NOW-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
    ]);

    GuestConciergeAgent::fake(['Welcome back!']);

    $payload = whatsappPayloadOf(whatsappMessage('201151793758', 'Hi', 'wamid.X'));
    $this->postJson('/api/whatsapp', $payload, whatsappSignatureHeader($payload))->assertOk();

    expect(WhatsAppInboundMessage::first()->hotel_id)->toBe($currentGuest->hotel_id);
});
