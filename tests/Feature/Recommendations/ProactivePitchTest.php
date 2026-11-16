<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\UpdateRecommendationTool;
use App\Enums\DeliveryChannel;
use App\Enums\PitchOpening;
use App\Enums\PitchResult;
use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveSkipReason;
use App\Enums\ProactiveTrigger;
use App\Enums\RecommendationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Support\Audit\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    rapPitchingOn();
    // 07:05 UTC is 10:05 in Riyadh.
    $this->travelTo(Carbon::parse('2026-10-10 07:05:00', 'UTC'));
});

/**
 * @return array{0: Guest, 1: Reservation, 2: Recommendation, 3: Hotel}
 */
function proactivePitchSetup(array $guest = [], ?string $description = 'Reef trip with a guide.'): array
{
    $hotel = rapProactiveOn(rapHotel(), ['triggers' => ['mid_stay' => false, 'recommendation_approved' => false, 'upcoming_activity' => false]]);
    [$guest, $reservation] = rapInHouseStay($hotel, $guest);
    $activity = abActivity($hotel, ['name' => 'Snorkeling', 'description' => $description]);
    $recommendation = rapRecommendation($reservation, $activity, 'approved');
    rapInbound($guest, now()->subHours(2));

    return [$guest, $reservation, $recommendation, $hotel];
}

it('records a proactive offer as a pitch, delivered only once WhatsApp took it', function () {
    gsFakeWhatsApp();
    [$guest, , $recommendation] = proactivePitchSetup();

    rapSweep();

    $row = rapProactiveRows($guest)->sole();
    $decision = PitchDecision::sole();

    expect($row->status)->toBe(ProactiveMessageStatus::SENT)
        ->and($decision->opening)->toBe(PitchOpening::PROACTIVE)
        ->and($decision->explicit_request)->toBeFalse()
        ->and($decision->result)->toBe(PitchResult::PITCHED)
        ->and($decision->proactive_message_id)->toBe($row->id)
        ->and($recommendation->fresh())
        ->pitch_decision_id->toBe($decision->id)
        ->status->toBe(RecommendationStatus::SENT)
        ->delivery_channel->toBe(DeliveryChannel::WHATSAPP);
});

it('counts a proactive offer toward the cap, and a "no" to it starts the retry rule', function () {
    gsFakeWhatsApp();
    config(['pitching.max_unsolicited_per_stay' => 1]);
    [$guest, $reservation, $recommendation, $hotel] = proactivePitchSetup();
    rapRecommendation($reservation, rapActivity($hotel, 'Cooking class'), 'approved', ['priority' => 2]);
    rapSweep();

    // Before the guest answers, the cap is spent.
    expect(rapTurn($guest, $reservation)->eligible)->toBeFalse();

    // The guest declines the proactive offer: one retry is now allowed.
    EventLogger::asAiAgent(fn () => (new UpdateRecommendationTool($reservation))->handle(new Request([
        'recommendation_id' => $recommendation->id, 'action' => 'rejected', 'evidence_quote' => 'no thanks', 'confidence' => 0.9,
    ])));

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeTrue()
        ->and($turn->isRetryTurn)->toBeTrue()
        ->and($turn->offer()->name)->toBe('Cooking class');
});

it('adds the message to the guest\'s conversation and tells the Concierge about it', function () {
    gsFakeWhatsApp();
    [$guest, $reservation, $recommendation, $hotel] = proactivePitchSetup();

    rapSweep();

    $row = rapProactiveRows($guest)->sole();
    $stored = DB::table('agent_conversation_messages')->where('conversation_id', $row->conversation_id)->sole();
    $instructions = (string) (new GuestConciergeAgent($guest, $hotel, $reservation))->instructions();

    expect($stored->role)->toBe('assistant')
        ->and($stored->content)->toBe($row->body)
        ->and($instructions)->toContain($row->body)
        ->toContain("recommendation_id {$recommendation->id}");
});

it('writes the message in the guest\'s language, with every placeholder filled', function (string $language) {
    gsFakeWhatsApp();
    [$guest] = proactivePitchSetup(['preferred_language' => $language, 'first_name' => 'Sara']);

    rapSweep();

    $row = rapProactiveRows($guest)->sole();

    expect($row->locale)->toBe($language)
        ->and($row->body)->toContain('Sara')->toContain('Snorkeling')->toContain('Reef trip with a guide.')
        ->and($row->body)->not->toMatch('/:(guest|hotel|activity|description|date|time)\b/');
})->with(['en', 'ar']);

it('uses the wording without a description when the activity has none', function () {
    gsFakeWhatsApp();
    [$guest] = proactivePitchSetup([], null);

    rapSweep();

    expect(rapProactiveRows($guest)->sole())
        ->status->toBe(ProactiveMessageStatus::SENT)
        ->body->toBe(__('proactive.first_morning.without_description', ['guest' => $guest->first_name, 'hotel' => $guest->hotel->name, 'activity' => 'Snorkeling'], 'en'));
});

it('skips a reminder whose booking has no time to name', function () {
    gsFakeWhatsApp();
    [$guest, $reservation, , $hotel] = proactivePitchSetup();
    $hotel = rapProactiveOn($hotel, ['triggers' => ['first_morning' => false, 'upcoming_activity' => true]]);
    $booking = abBook($hotel, rapActivity($hotel, 'Safari'), '2026-10-10 15:00:00', 1, ['guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'status' => 'confirmed']);
    rapSweep();
    $booking->forceFill(['scheduled_for' => null])->saveQuietly();
    $this->travelTo(Carbon::parse('2026-10-10 08:05:00', 'UTC'));

    rapSweep();

    expect(rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::UPCOMING_ACTIVITY)->refresh())
        ->status->toBe(ProactiveMessageStatus::SKIPPED)
        ->reason->toBe(ProactiveSkipReason::MISSING_DATA);
});
