<?php

use App\Enums\AttributionMethod;
use App\Enums\OutcomeType;
use App\Models\AiActivity;
use App\Models\ProactiveMessage;
use App\Services\RecommendationOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * SPEC-083 R5: what the Concierge offered a guest, how they answered, and
 * every proactive message sent or skipped, as guest_outreach entries.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    rapPitchingOn();
});

function outreachRows()
{
    return AiActivity::withoutGlobalScope('hotel')->where('category', 'guest_outreach')->orderBy('occurred_at')->orderBy('created_at')->get();
}

it('records a pitch the guest received, targeting the recommendation', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');

    rapPitch($guest, $reservation);

    $row = outreachRows()->sole();

    expect($row->action)->toBe('pitch.offered')
        ->and($row->agent->value)->toBe('concierge')
        ->and($row->result->value)->toBe('succeeded')
        ->and($row->target_id)->toBe($recommendation->id)
        ->and($row->acted_for_id)->toBe($guest->id)
        ->and($row->hotel_id)->toBe($hotel->id);
});

it('records how the guest answered a pitch', function (string $action, string $expected) {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    $turn = rapPitch($guest, $reservation);

    rapGuestSays($turn, $reservation, $action);

    expect(outreachRows()->pluck('action')->all())->toBe(['pitch.offered', $expected]);
})->with([
    'accepted' => ['accepted', 'pitch.accepted'],
    'declined' => ['rejected', 'pitch.declined'],
]);

it('records a pitch that went unanswered until the guest left', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    rapPitch($guest, $reservation);

    app(RecommendationOutcomeService::class)->record($recommendation->fresh(), OutcomeType::EXPIRED, AttributionMethod::CONVERSATIONAL);

    expect(outreachRows()->pluck('action')->last())->toBe('pitch.no_answer');
});

it('records proactive messages sent and skipped, with no run and the skip reason', function () {
    $hotel = cmaHotel();
    [$guest] = rapInHouseStay($hotel);

    $message = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'trigger' => 'first_morning',
        'event_key' => 'first_morning:1',
        'status' => 'scheduled',
        'due_at' => now(),
        'valid_until' => now()->addHours(3),
    ]))->save();

    $message->forceFill(['status' => 'skipped', 'reason' => 'quiet_hours'])->save();
    $message->forceFill(['status' => 'skipped', 'reason' => 'quiet_hours'])->save();

    $sent = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'trigger' => 'mid_stay',
        'event_key' => 'mid_stay:1',
        'status' => 'sending',
        'due_at' => now(),
        'valid_until' => now()->addHours(3),
    ]))->save();
    $sent->forceFill(['status' => 'sent', 'sent_at' => now(), 'body' => 'Enjoying your stay?'])->save();

    $rows = outreachRows();

    expect($rows->pluck('action')->all())->toBe(['proactive.skipped', 'proactive.sent'])
        ->and($rows[0]->reason)->toBe('quiet_hours')
        ->and($rows[0]->target_id)->toBe($message->id)
        ->and($rows[0]->ai_run_id)->toBeNull()
        ->and($rows[0]->tool)->toBe('SendProactiveMessageJob')
        ->and($rows[0]->agent->value)->toBe('concierge')
        ->and($rows[0]->acted_for_id)->toBe($guest->id)
        ->and(json_encode($rows[1]->getAttributes()))->not->toContain('Enjoying your stay');
});
