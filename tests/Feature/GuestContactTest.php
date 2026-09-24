<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\InboundMessageStatus;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Services\GuestContactService;
use App\Services\Metering\MeteringService;
use App\Services\StayService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config([
        'app.api_key' => 'test-api-key',
        'services.whatsapp.verify_token' => 'test-verify-token',
        'services.whatsapp.app_secret' => 'test-app-secret',
        'services.whatsapp.phone_number_id' => 'test-phone-number-id',
        'services.whatsapp.access_token' => 'test-access-token',
    ]);
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
});

function contactHotel(): Hotel
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Contact Hotel',
        'slug' => 'contact-hotel-'.Str::lower(Str::random(6)),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function contactGuest(Hotel $hotel, string $phone = '201151793758'): Guest
{
    return Guest::create(['hotel_id' => $hotel->id, 'phone_number' => $phone]);
}

function contactStay(Guest $guest, string $arrival, string $departure): Stay
{
    $reservation = Reservation::create([
        'hotel_id' => $guest->hotel_id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'status' => 'confirmed',
    ]);

    return app(StayService::class)->syncForReservation($reservation)->first();
}

/**
 * A guest message as the agent package stores it, at a chosen time.
 */
function contactMessage(Guest $guest, string $at, string $role = 'user'): void
{
    $conversation = Conversation::firstOrCreate(
        ['participant_type' => $guest->getMorphClass(), 'participant_id' => $guest->id],
        ['id' => (string) Str::uuid7(), 'title' => 'Chat'],
    );

    $message = new ConversationMessage([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'participant_type' => $guest->getMorphClass(),
        'participant_id' => $guest->id,
        'agent' => GuestConciergeAgent::class,
        'role' => $role,
        'content' => 'Hello',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
    $message->created_at = $at;
    $message->updated_at = $at;
    $message->save();
}

function contactJob(Guest $guest, ?Reservation $reservation = null): ProcessInboundWhatsAppMessageJob
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
        reservation: $reservation,
        devicePaired: false,
    );
}

function runContactJob(ProcessInboundWhatsAppMessageJob $job): void
{
    $job->handle(app(WhatsAppMessageService::class), app(MeteringService::class));
}

it('stamps first and last contact on the guest and the resolved stay', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $stay = contactStay($guest, now()->subDay()->toDateString(), now()->addDays(3)->toDateString());
    GuestConciergeAgent::fake(['Checkout is at noon.']);

    // Meta's timestamp, not the time the queue got round to the message.
    $sentAt = now()->subMinutes(7)->startOfSecond();
    $payload = ['entry' => [['changes' => [['value' => ['messages' => [[
        'id' => 'wamid.CONTACT',
        'from' => '201151793758',
        'timestamp' => (string) $sentAt->timestamp,
        'text' => ['body' => 'What time is checkout?'],
    ]]]]]]]];
    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-app-secret');

    $this->postJson('/api/whatsapp', $payload, ['X-Hub-Signature-256' => $signature])->assertOk();

    $guest->refresh();
    $stay->refresh();

    expect($guest->first_contacted_at->equalTo($sentAt))->toBeTrue()
        ->and($guest->last_contacted_at->equalTo($sentAt))->toBeTrue()
        ->and($stay->first_contacted_at->equalTo($sentAt))->toBeTrue()
        ->and($stay->last_contacted_at->equalTo($sentAt))->toBeTrue();
});

it('never moves first_contacted_at later or last_contacted_at earlier', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $stay = contactStay($guest, '2026-09-10', '2026-09-20');
    $service = app(GuestContactService::class);

    // Out of order, the way two workers can finish.
    $service->recordInbound($guest, $stay, Carbon::parse('2026-09-15 12:00:00'));
    $service->recordInbound($guest, $stay, Carbon::parse('2026-09-12 08:00:00'));
    $service->recordInbound($guest, $stay, Carbon::parse('2026-09-18 20:00:00'));
    $service->recordInbound($guest, $stay, Carbon::parse('2026-09-14 10:00:00'));

    foreach ([$guest->fresh(), $stay->fresh()] as $record) {
        expect($record->first_contacted_at->toDateTimeString())->toBe('2026-09-12 08:00:00')
            ->and($record->last_contacted_at->toDateTimeString())->toBe('2026-09-18 20:00:00');
    }
});

it('stamps contact even when the AI call throws', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $stay = contactStay($guest, now()->subDay()->toDateString(), now()->addDays(3)->toDateString());
    GuestConciergeAgent::fake(fn () => throw new RuntimeException('model unavailable'));

    $job = contactJob($guest, $stay->reservation);
    runContactJob($job);

    expect($job->inbound->fresh()->reply_text)->toBe(ProcessInboundWhatsAppMessageJob::FAILED_REPLY)
        ->and($guest->fresh()->first_contacted_at)->not->toBeNull()
        ->and($stay->fresh()->first_contacted_at)->not->toBeNull();
});

it('does not write an event_log row per inbound message', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $stay = contactStay($guest, now()->subDay()->toDateString(), now()->addDays(3)->toDateString());
    GuestConciergeAgent::fake(['one', 'two']);

    $subjectEvents = fn () => EventLog::withoutGlobalScope('hotel')
        ->whereIn('subject_id', [$guest->id, $stay->id])
        ->count();
    $before = $subjectEvents();

    runContactJob(contactJob($guest, $stay->reservation));
    runContactJob(contactJob($guest, $stay->reservation));

    expect($guest->fresh()->last_contacted_at)->not->toBeNull()
        ->and($subjectEvents())->toBe($before);
});

it('still replies to the guest when contact stamping throws', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    GuestConciergeAgent::fake(['Happy to help.']);
    $this->mock(GuestContactService::class, fn ($mock) => $mock
        ->shouldReceive('recordInbound')->andThrow(new RuntimeException('database hiccup')));

    $job = contactJob($guest);
    runContactJob($job);

    Http::assertSent(fn ($request) => $request['text']['body'] === 'Happy to help.');
    expect($job->inbound->fresh()->status)->toBe(InboundMessageStatus::REPLIED);
});

it('ignores contact fields sent through the guest update endpoint', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $admin = User::findOrFail($hotel->owner_id);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", [
            'first_name' => 'Anna',
            'first_contacted_at' => '2026-01-01 00:00:00',
            'last_contacted_at' => '2026-01-02 00:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('body.first_contacted_at', null)
        ->assertJsonPath('body.last_contacted_at', null);

    expect($guest->fresh()->first_name)->toBe('Anna')
        ->and($guest->fresh()->first_contacted_at)->toBeNull();
});

it('backfills stays using the same stay-selection rule as live recognition', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $march = contactStay($guest, '2026-03-01', '2026-03-08');
    $september = contactStay($guest, '2026-09-10', '2026-09-17');

    contactMessage($guest, '2026-02-20 09:00:00');  // before both: the next arrival, March
    contactMessage($guest, '2026-03-03 10:00:00');  // in-house in March
    contactMessage($guest, '2026-03-03 10:00:05', role: 'assistant');  // the reply is not contact
    contactMessage($guest, '2026-09-12 18:30:00');  // in-house in September
    contactMessage($guest, '2026-10-05 11:00:00');  // after both: the most recent past stay

    $this->artisan('guests:backfill-contact-timestamps')->assertSuccessful();

    $guest->refresh();
    $march->refresh();
    $september->refresh();

    expect($march->first_contacted_at->toDateTimeString())->toBe('2026-02-20 09:00:00')
        ->and($march->last_contacted_at->toDateTimeString())->toBe('2026-03-03 10:00:00')
        ->and($september->first_contacted_at->toDateTimeString())->toBe('2026-09-12 18:30:00')
        ->and($september->last_contacted_at->toDateTimeString())->toBe('2026-10-05 11:00:00')
        ->and($guest->first_contacted_at->toDateTimeString())->toBe('2026-02-20 09:00:00')
        ->and($guest->last_contacted_at->toDateTimeString())->toBe('2026-10-05 11:00:00');

    $event = EventLog::withoutGlobalScope('hotel')
        ->where('event_type', 'hotel.guest_contact_backfilled')
        ->sole();

    expect($event->changes)->toMatchArray([
        'guests' => 1,
        'stays' => 2,
        'messages' => 4,
        'unmapped_messages' => 0,
    ]);
});

it('is idempotent when the backfill runs twice', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    $stay = contactStay($guest, '2026-09-10', '2026-09-17');
    contactMessage($guest, '2026-09-11 09:00:00');
    contactMessage($guest, '2026-09-13 09:00:00');

    $this->artisan('guests:backfill-contact-timestamps')->assertSuccessful();
    $first = $stay->fresh()->only(['first_contacted_at', 'last_contacted_at']);

    $this->artisan('guests:backfill-contact-timestamps')->assertSuccessful();

    expect($stay->fresh()->only(['first_contacted_at', 'last_contacted_at']))->toEqual($first)
        ->and($first['first_contacted_at']->toDateTimeString())->toBe('2026-09-11 09:00:00')
        ->and($first['last_contacted_at']->toDateTimeString())->toBe('2026-09-13 09:00:00');
});

it('writes nothing on a dry run', function () {
    $hotel = contactHotel();
    $guest = contactGuest($hotel);
    contactMessage($guest, '2026-09-11 09:00:00');

    $this->artisan('guests:backfill-contact-timestamps', ['--dry-run' => true])->assertSuccessful();

    expect($guest->fresh()->first_contacted_at)->toBeNull()
        ->and(EventLog::withoutGlobalScope('hotel')->where('event_type', 'hotel.guest_contact_backfilled')->exists())->toBeFalse();
});

it('does not stamp another hotel\'s guest during backfill', function () {
    $hotel = contactHotel();
    $otherHotel = contactHotel();
    // The same person, known at two hotels — only one of them was messaged.
    $guest = contactGuest($hotel);
    $sameNumberElsewhere = contactGuest($otherHotel);
    $otherStay = contactStay($sameNumberElsewhere, '2026-09-10', '2026-09-17');
    contactMessage($guest, '2026-09-12 09:00:00');

    $this->artisan('guests:backfill-contact-timestamps')->assertSuccessful();

    expect($guest->fresh()->first_contacted_at)->not->toBeNull()
        ->and($sameNumberElsewhere->fresh()->first_contacted_at)->toBeNull()
        ->and($otherStay->fresh()->first_contacted_at)->toBeNull();
});
