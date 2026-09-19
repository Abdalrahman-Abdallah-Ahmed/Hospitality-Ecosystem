<?php

use App\Ai\Tools\GetRecommendationsTool;
use App\Ai\Tools\UpdateRecommendationTool;
use App\Enums\ActorKind;
use App\Enums\DeliveryChannel;
use App\Enums\MeterFeature;
use App\Enums\OutcomeType;
use App\Enums\RecommendationStatus;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\MeterEvent;
use App\Models\Recommendation;
use App\Services\Metering\MeteringService;
use App\Services\RecommendationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function deliveryMeterEvents(Recommendation $recommendation)
{
    return MeterEvent::query()
        ->where('feature_code', MeterFeature::RECOMMENDATIONS_DELIVERED->value)
        ->where('source_id', $recommendation->id)
        ->get();
}

function deliver(Recommendation $recommendation, ?Carbon $at = null, DeliveryChannel $channel = DeliveryChannel::WHATSAPP): bool
{
    return app(RecommendationDeliveryService::class)->markDelivered(
        $recommendation,
        $channel,
        $at ?? now(),
        ActorKind::AI_AGENT,
    );
}

it('stamps delivery once and meters it once', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);
    $firstAt = now()->subMinutes(20)->startOfSecond();

    expect(deliver($recommendation, $firstAt))->toBeTrue()
        ->and(deliver($recommendation, now(), DeliveryChannel::PHONE))->toBeFalse();

    $recommendation->refresh();
    $events = deliveryMeterEvents($recommendation);

    // The first stamp stands; a second caller changes nothing.
    expect($recommendation->delivered_at->equalTo($firstAt))->toBeTrue()
        ->and($recommendation->delivery_channel)->toBe(DeliveryChannel::WHATSAPP)
        ->and($events)->toHaveCount(1)
        ->and($events->first()->actor_kind)->toBe(ActorKind::AI_AGENT)
        // A count, never a value.
        ->and($events->first()->metadata)->toBe(['channel' => 'whatsapp'])
        ->and(EventLog::withoutGlobalScope('hotel')
            ->where('event_type', 'recommendation.delivered')
            ->where('subject_id', $recommendation->id)
            ->count())->toBe(1);
});

it('moves a pending recommendation to sent on delivery', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$pending] = wp5Recommendation($hotel);
    [$accepted] = wp5Recommendation($hotel, ['status' => RecommendationStatus::ACCEPTED]);

    deliver($pending);
    deliver($accepted);

    // Only PENDING moves; a guest's decision is never walked back to "sent".
    expect($pending->fresh()->status)->toBe(RecommendationStatus::SENT)
        ->and($accepted->fresh()->status)->toBe(RecommendationStatus::ACCEPTED);
});

it('does not stamp delivery when the agent only read the recommendations', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $listed = json_decode((string) (new GetRecommendationsTool($recommendation->reservation))->handle(new Request([])), true);

    expect($listed)->toHaveCount(1)
        ->and($recommendation->fresh()->delivered_at)->toBeNull()
        ->and($recommendation->fresh()->status)->toBe(RecommendationStatus::PENDING)
        ->and(deliveryMeterEvents($recommendation))->toBeEmpty();
});

it('stamps delivery when the guest reacts to it in chat', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    (new UpdateRecommendationTool($recommendation->reservation))->handle(new Request([
        'recommendation_id' => $recommendation->id,
        'action' => 'accepted',
        'confidence' => 0.9,
        'evidence_quote' => 'Yes please, book it',
    ]));

    $recommendation->refresh();

    expect($recommendation->delivered_at)->not->toBeNull()
        ->and($recommendation->delivery_channel)->toBe(DeliveryChannel::WHATSAPP)
        ->and($recommendation->status)->toBe(RecommendationStatus::ACCEPTED)
        ->and(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::ACCEPTED)
        ->and(deliveryMeterEvents($recommendation))->toHaveCount(1);
});

it('stamps delivery when staff record a face-to-face decline', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", [
            'outcome' => 'declined',
            'channel' => 'face_to_face',
            'decline_reason' => 'price',
            'occurred_at' => '2026-09-18 16:30:00',
        ])
        ->assertCreated();

    $recommendation->refresh();
    $event = deliveryMeterEvents($recommendation)->sole();

    expect($recommendation->delivered_at->toDateTimeString())->toBe('2026-09-18 16:30:00')
        ->and($recommendation->delivery_channel)->toBe(DeliveryChannel::FACE_TO_FACE)
        ->and($event->actor_kind)->toBe(ActorKind::USER);
});

it('does not stamp delivery when staff record not_delivered', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", ['outcome' => 'not_delivered'])
        ->assertCreated();

    expect($recommendation->fresh()->delivered_at)->toBeNull()
        ->and(deliveryMeterEvents($recommendation))->toBeEmpty();
});

it('stamps delivery when a booking carries the recommendation id', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    $booking = wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
        'channel' => 'desk',
    ]);

    $recommendation->refresh();

    // "desk" is not a delivery channel of its own: a person at the hotel.
    expect($recommendation->delivered_at->equalTo($booking->created_at))->toBeTrue()
        ->and($recommendation->delivery_channel)->toBe(DeliveryChannel::FACE_TO_FACE)
        ->and(deliveryMeterEvents($recommendation))->toHaveCount(1);
});

it('does not stamp delivery for an inferred booking', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);
    (new MatchRecommendationOutcomesJob($hotel->id))->handle();

    // Matched, but a match says nothing about whether our offer reached them.
    expect(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::BOOKED)
        ->and($recommendation->fresh()->delivered_at)->toBeNull()
        ->and(deliveryMeterEvents($recommendation))->toBeEmpty();
});

it('still saves the booking when delivery metering throws', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    $this->partialMock(MeteringService::class, fn ($mock) => $mock
        ->shouldReceive('recordForHotel')->andThrow(new RuntimeException('metering exploded')));

    $booking = wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
    ]);

    expect(Booking::find($booking->id))->not->toBeNull()
        ->and(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::BOOKED)
        ->and($recommendation->fresh()->delivered_at)->not->toBeNull()
        ->and(MeterEvent::count())->toBe(0);
});

it('ignores delivered_at sent through the recommendation update endpoint', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->putJson("/api/recommendation/{$recommendation->id}", [
            'reason' => 'Loves the sea',
            'delivered_at' => '2026-09-01 10:00:00',
            'delivery_channel' => 'whatsapp',
        ])
        ->assertOk()
        ->assertJsonPath('body.delivered_at', null)
        ->assertJsonPath('body.delivery_channel', null);

    expect($recommendation->fresh()->reason)->toBe('Loves the sea')
        ->and($recommendation->fresh()->delivered_at)->toBeNull()
        ->and(deliveryMeterEvents($recommendation))->toBeEmpty();
});
