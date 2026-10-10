<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\SetContactPreferenceTool;
use App\Enums\InboundMessageStatus;
use App\Enums\MeterFeature;
use App\Enums\PitchGate;
use App\Enums\SenderType;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\MeterEvent;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
    Sleep::fake();
    rapPitchingOn();
});

function optOutJob(Guest $guest, Reservation $reservation, string $text): ProcessInboundWhatsAppMessageJob
{
    return new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => $guest->phone_number, 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: $guest->phone_number,
        messageText: $text,
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $guest->hotel,
        reservation: $reservation,
        devicePaired: false,
    );
}

it('opts a guest out on a keyword, in code, with a fixed reply in their language', function (string $text, string $language) {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel, ['preferred_language' => $language]);
    GuestConciergeAgent::fake()->preventStrayPrompts();
    $whatsApp = gsFakeWhatsApp();

    optOutJob($guest, $reservation, $text)->handle($whatsApp, app(MeteringService::class));

    expect($guest->fresh())
        ->proactive_opted_out_at->not->toBeNull()
        ->proactive_opt_out_source->toBe('guest_message')
        ->and($whatsApp->sent[0]['text'])->toBe(__('proactive.opt_out.confirmed', [], $language))
        ->and(EventLog::where('subject_id', $guest->id)->where('event_type', 'guest.opted_out')->count())->toBe(1)
        ->and(MeterEvent::where('feature_code', MeterFeature::AI_MESSAGES->value)->count())->toBe(0);
})->with([
    'STOP' => ['STOP', 'en'],
    'spaced and punctuated' => ['  stop! ', 'en'],
    'Arabic' => ['توقف', 'ar'],
]);

it('records a repeated STOP once, and START resumes', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    GuestConciergeAgent::fake()->preventStrayPrompts();
    $whatsApp = gsFakeWhatsApp();

    optOutJob($guest, $reservation, 'STOP')->handle($whatsApp, app(MeteringService::class));
    optOutJob($guest, $reservation, 'stop')->handle($whatsApp, app(MeteringService::class));

    expect(EventLog::where('subject_id', $guest->id)->where('event_type', 'guest.opted_out')->count())->toBe(1);

    optOutJob($guest, $reservation, 'START')->handle($whatsApp, app(MeteringService::class));

    expect($guest->fresh()->proactive_opted_out_at)->toBeNull()
        ->and(EventLog::where('subject_id', $guest->id)->where('event_type', 'guest.opted_in')->count())->toBe(1);
});

it('does not treat STOP inside a sentence as an opt-out', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    GuestConciergeAgent::fake(['Of course, which stop do you mean?']);

    optOutJob($guest, $reservation, 'Where is the bus stop?')->handle(gsFakeWhatsApp(), app(MeteringService::class));

    expect($guest->fresh()->proactive_opted_out_at)->toBeNull();
});

it('lets the Concierge opt a guest out in their own words', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);

    $result = gsRunTool(new SetContactPreferenceTool($guest), ['preference' => 'opt_out', 'guest_words' => 'please stop sending me offers']);

    expect($result)->toStartWith('Done')
        ->and($guest->fresh()->isProactiveOptedOut())->toBeTrue();
});

it('blocks unsolicited pitches for an opted-out guest but still answers what they ask', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel), 'approved');
    gsRunTool(new SetContactPreferenceTool($guest), ['preference' => 'opt_out', 'guest_words' => 'stop the offers']);
    $guest->refresh();

    $unsolicited = rapTurn($guest, $reservation->fresh());
    rapClassifierSays('asks_what_to_do', 'what can I do');
    $asked = rapTurn($guest, $reservation->fresh(), 'What can I do tomorrow?');

    expect($unsolicited->eligible)->toBeFalse()
        ->and(collect($unsolicited->decision->gates)->firstWhere('gate', PitchGate::OPTED_OUT->value)['passed'])->toBeFalse()
        ->and($asked->eligible)->toBeTrue();
});

it('still sends an opted-out guest the notice about their own request', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour());
    gsRunTool(new SetContactPreferenceTool($guest), ['preference' => 'opt_out', 'guest_words' => 'stop']);
    $whatsApp = gsFakeWhatsApp();

    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), ['title' => 'Towels', 'description' => 'Two more towels', 'kind' => 'service_request']);
    $task = Task::sole();
    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($task->fresh()->guest_notice_status->value)->toBe('sent');
});
