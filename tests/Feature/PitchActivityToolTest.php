<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\PitchActivityTool;
use App\Enums\DeliveryChannel;
use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeType;
use App\Enums\PitchResult;
use App\Enums\RecommendationStatus;
use App\Enums\SenderType;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Guest;
use App\Models\PitchDecision;
use App\Models\Reservation;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\Pitching\PitchCoordinator;
use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(fn () => rapPitchingOn());

function pitchToolFor($turn, Reservation $reservation): PitchActivityTool
{
    return new PitchActivityTool($turn, $reservation->stay);
}

it('stages the turn\'s one approved offer without stamping delivery', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    $turn = rapTurn($guest, $reservation);

    $result = (string) pitchToolFor($turn, $reservation)->handle(new Request(['guest_words' => 'this evening']));

    expect($result)->toStartWith("Staged recommendation {$recommendation->id} for Snorkeling.")
        ->and($recommendation->fresh()->pitch_decision_id)->toBe($turn->decision->id)
        ->and($recommendation->fresh()->delivered_at)->toBeNull()
        ->and($turn->staged()->recommendationId)->toBe($recommendation->id)
        ->and($turn->mayPitch())->toBeFalse();
});

it('refuses to stage', function (string $case) {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    $turn = rapTurn($guest, $reservation);
    $words = 'this evening';

    match ($case) {
        'words not in the message' => $words = 'I love boats',
        'already staged' => pitchToolFor($turn, $reservation)->handle(new Request(['guest_words' => 'this evening'])),
        'escalated this turn' => $turn->markBlockingRequest(),
        'rejected by an approver meanwhile' => app(RecommendationApprovalService::class)->reject($recommendation, rapAdmin($hotel)),
        'cap reached meanwhile' => rapPitch($guest, $reservation),
    };

    $before = $recommendation->fresh()->pitch_decision_id;
    $result = (string) pitchToolFor($turn, $reservation)->handle(new Request(['guest_words' => $words]));

    expect($result)->not->toStartWith('Staged')
        ->and($recommendation->fresh()->pitch_decision_id)->toBe($before);
})->with(['words not in the message', 'already staged', 'escalated this turn', 'rejected by an approver meanwhile', 'cap reached meanwhile']);

it('marks a staged pitch delivered once the reply is sent', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');

    $turn = rapPitch($guest, $reservation);
    $decision = $turn->decision->fresh();

    expect($recommendation->fresh())
        ->status->toBe(RecommendationStatus::SENT)
        ->delivered_at->not->toBeNull()
        ->delivery_channel->toBe(DeliveryChannel::WHATSAPP)
        ->and($recommendation->fresh()->outcome->outcome)->toBe(OutcomeType::DELIVERED)
        ->and($decision->result)->toBe(PitchResult::PITCHED)
        ->and($decision->recommendation_id)->toBe($recommendation->id)
        ->and($decision->chosen_rank)->toBe(1)
        ->and($decision->mention_verified)->toBeTrue();
});

it('records a staged pitch as failed when the reply is never sent', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    $turn = rapTurn($guest, $reservation);
    pitchToolFor($turn, $reservation)->handle(new Request(['guest_words' => 'this evening']));

    app(PitchCoordinator::class)->abandon($guest, now()->subMinute());

    // Released: it never reached the guest, so it can be offered again.
    expect($turn->decision->fresh()->result)->toBe(PitchResult::REPLY_FAILED)
        ->and($recommendation->fresh()->delivered_at)->toBeNull()
        ->and($recommendation->fresh()->pitch_decision_id)->toBeNull()
        ->and($recommendation->fresh()->isOfferable())->toBeTrue();
});

function pitchingJob(Guest $guest, Reservation $reservation): ProcessInboundWhatsAppMessageJob
{
    return new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => $guest->phone_number, 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: $guest->phone_number,
        messageText: 'Any plans for this evening?',
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $guest->hotel,
        reservation: $reservation,
        devicePaired: false,
    );
}

/**
 * The faked Concierge calls the pitch tool it was given, as the model would.
 */
function conciergeThatPitches(): void
{
    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });

    GuestConciergeAgent::fake(function () use (&$agent) {
        $tool = collect($agent->tools())->first(fn ($tool) => $tool instanceof PitchActivityTool);
        $tool?->handle(new Request(['guest_words' => 'this evening']));

        return 'Have a lovely evening! You might enjoy Snorkeling tomorrow.';
    });
}

it('completes the pitch only after the reply is sent, and as failed when sending gives up', function (bool $sendFails) {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    conciergeThatPitches();
    $whatsApp = gsFakeWhatsApp($sendFails ? 99 : 0);
    $job = pitchingJob($guest, $reservation);

    try {
        $job->handle($whatsApp, app(MeteringService::class));
    } catch (RuntimeException) {
        $job->failed(null);
    }

    $decision = PitchDecision::sole();

    expect($decision->result)->toBe($sendFails ? PitchResult::REPLY_FAILED : PitchResult::PITCHED)
        ->and($recommendation->fresh()->delivered_at === null)->toBe($sendFails)
        ->and($recommendation->fresh()->pitch_decision_id)->toBe($sendFails ? null : $decision->id);
})->with(['sent' => false, 'send fails' => true]);
