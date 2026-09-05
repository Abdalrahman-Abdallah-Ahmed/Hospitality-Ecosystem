<?php

use App\Enums\AttributionMethod;
use App\Enums\ChargeModel;
use App\Enums\EvidenceLevel;
use App\Enums\OutcomeType;
use App\Enums\UserRole;
use App\Models\RecommendationOutcome;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RecommendationOutcomeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

// --- the definition ------------------------------------------------------

it('counts a booking as the conversion even when no payment ever occurs', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    // An all-inclusive guest books an included activity. No money will ever
    // move, and the recommendation worked perfectly.
    $booking = wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
        'charge_model' => ChargeModel::INCLUDED->value,
    ]);

    app(BookingService::class)->realise($booking);

    $outcome = wp5Outcome($recommendation);

    expect($outcome->outcome)->toBe(OutcomeType::BOOKED)
        ->and($outcome->attribution_method)->toBe(AttributionMethod::DIRECT)
        ->and($outcome->evidence_level)->toBe(EvidenceLevel::L1)
        ->and($booking->transactions()->count())->toBe(0);
});

it('records acceptance and booking as separate states', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::ACCEPTED,
        AttributionMethod::CONVERSATIONAL,
        attributes: ['confidence' => 0.9],
    );

    // The guest said yes; no commitment exists yet. That distance is the
    // fulfilment gap, and collapsing the two would hide it.
    expect(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::ACCEPTED)
        ->and(OutcomeType::ACCEPTED->reachedAcceptance())->toBeTrue()
        ->and(OutcomeType::BOOKED->reachedAcceptance())->toBeTrue()
        ->and(OutcomeType::DECLINED->reachedAcceptance())->toBeFalse();
});

// --- conversational capture ---------------------------------------------

it('records a declined outcome with the guest quote and reason', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DECLINED,
        AttributionMethod::CONVERSATIONAL,
        attributes: [
            'decline_reason' => 'price',
            'evidence_quote' => "maybe another time, it's a bit expensive for us",
            'confidence' => 0.86,
            'channel' => 'whatsapp',
        ],
    );

    $outcome = wp5Outcome($recommendation);

    // A refusal leaves no booking and no payment — only the conversation has
    // it, which is why this path exists at all.
    expect($outcome->outcome)->toBe(OutcomeType::DECLINED)
        ->and($outcome->evidence_level)->toBe(EvidenceLevel::L1)
        ->and($outcome->decline_reason)->toBe('price')
        ->and($outcome->evidence_quote)->toContain('a bit expensive');
});

it('writes DELIVERED rather than ACCEPTED when confidence is below threshold', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    // An ambiguous "we'll see" is not a yes. Politeness reads as agreement
    // far more readily in some languages than in English.
    $outcome = app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::ACCEPTED,
        AttributionMethod::CONVERSATIONAL,
        attributes: ['confidence' => 0.4],
    );

    expect($outcome->outcome)->toBe(OutcomeType::DELIVERED);
});

it('keeps a confident acceptance as accepted', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $outcome = app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::ACCEPTED,
        AttributionMethod::CONVERSATIONAL,
        attributes: ['confidence' => 0.95],
    );

    expect($outcome->outcome)->toBe(OutcomeType::ACCEPTED);
});

it('records not_delivered so an undelivered recommendation is not counted as a refusal', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", ['outcome' => 'not_delivered'])
        ->assertCreated();

    // A process failure, not a commercial one. Different teams, different fixes.
    expect(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::NOT_DELIVERED);
});

// --- staff capture -------------------------------------------------------

it('records a declined outcome from the staff endpoint', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", [
            'outcome' => 'declined',
            'channel' => 'face_to_face',
            'decline_reason' => 'price',
        ])
        ->assertCreated();

    $outcome = wp5Outcome($recommendation);

    expect($outcome->attribution_method)->toBe(AttributionMethod::STAFF)
        ->and($outcome->evidence_level)->toBe(EvidenceLevel::L1)
        ->and($outcome->recorded_by_user_id)->toBe($admin->id);
});

it('requires a reason when recording a declined outcome', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", ['outcome' => 'declined'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('decline_reason');
});

it('requires a booking when staff claim a booked outcome', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $this->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", ['outcome' => 'booked'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('booking_id');
});

it('lets an employee record an outcome for their own hotel', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    // The person at the desk is the one who hears "no".
    $seller = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(wp5Headers())->actingAs($seller, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", [
            'outcome' => 'declined',
            'decline_reason' => 'already booked elsewhere',
        ])
        ->assertCreated();
});

it('blocks an employee from recording an outcome for another hotel', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    $outsider = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(wp5Headers())->actingAs($outsider, 'sanctum')
        ->postJson("/api/recommendation/{$recommendation->id}/outcome", ['outcome' => 'delivered'])
        ->assertStatus(403);
});

// --- integrity -----------------------------------------------------------

it('rejects an outcome written without an attribution method', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    // Enforced by the database, not just by the service: the column is NOT
    // NULL with no default, so an unlabelled row cannot slip in through any
    // path at all.
    expect(fn () => RecommendationOutcome::create([
        'hotel_id' => $hotel->id,
        'recommendation_id' => $recommendation->id,
        'outcome' => OutcomeType::DELIVERED->value,
        'evidence_level' => EvidenceLevel::L1->value,
    ]))->toThrow(QueryException::class);
});

it('derives evidence level from attribution method and never accepts both from the caller', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    expect(fn () => app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DELIVERED,
        AttributionMethod::STAFF,
        attributes: ['evidence_level' => 'L1'],
    ))->toThrow(RuntimeException::class);

    $outcome = app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DELIVERED,
        AttributionMethod::STAFF,
    );

    expect($outcome->evidence_level)->toBe(EvidenceLevel::L1);
});

it('refuses a booked outcome with no booking', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    expect(fn () => app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::BOOKED,
        AttributionMethod::STAFF,
    ))->toThrow(RuntimeException::class);
});

it('refuses an inferred declined outcome', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);

    // A matching job only ever sees bookings. A refusal leaves nothing for it
    // to find, so claiming one was inferred is a lie about provenance.
    expect(fn () => app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DECLINED,
        AttributionMethod::INFERRED,
    ))->toThrow(RuntimeException::class);
});

it('refuses a booking from another hotel', function () {
    [, $hotel] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();
    [$recommendation, $guest] = wp5Recommendation($hotel);

    [, $otherGuest] = wp5Recommendation($otherHotel);
    $foreign = wp5Booking($otherHotel, ['guest_id' => $otherGuest->id]);

    expect(fn () => app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::BOOKED,
        AttributionMethod::STAFF,
        $foreign,
    ))->toThrow(RuntimeException::class);
});
