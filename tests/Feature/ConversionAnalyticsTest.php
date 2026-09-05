<?php

use App\Enums\AttributionMethod;
use App\Enums\ChargeModel;
use App\Enums\OutcomeType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RecommendationOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function conversionReport(User $admin)
{
    $from = now()->subMonth()->toDateString();
    $to = now()->addMonth()->toDateString();

    return test()->withHeaders(wp5Headers())->actingAs($admin, 'sanctum')
        ->getJson("/api/analytics/conversion?from={$from}&to={$to}");
}

/** A recommendation that produced a booking, credited directly. */
function bookedRecommendation($hotel, array $bookingOverrides = []): array
{
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    $booking = wp5Booking($hotel, array_merge([
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
    ], $bookingOverrides));

    return [$recommendation, $booking];
}

it('counts a booking as the conversion even when no payment ever occurs', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    bookedRecommendation($hotel, ['charge_model' => ChargeModel::INCLUDED->value]);

    $response = conversionReport($admin)->assertOk();

    // Revenue zero, outcome ideal. A payment-based definition would have
    // reported this as a failure.
    $response->assertJsonPath('body.booked', 1)
        ->assertJsonPath('body.included_bookings', 1)
        ->assertJsonPath('body.settled_value', null)
        ->assertJsonPath('body.settlement_rate', null)
        ->assertJsonPath('body.evidence_level', 'L1');

    expect($response->json('body.notes'))->toContain('all-inclusive and will never settle');
});

it('excludes INCLUDED bookings from the settlement rate and reports them separately', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    bookedRecommendation($hotel, ['charge_model' => ChargeModel::INCLUDED->value]);
    [, $payable] = bookedRecommendation($hotel, ['charge_model' => ChargeModel::PAY_ON_SITE->value]);

    app(BookingService::class)->linkSettlement($payable, wp5Transaction($hotel));

    // One of two bookings is settleable, and it settled — 100%, not 50%.
    conversionReport($admin)->assertOk()
        ->assertJsonPath('body.booked', 2)
        ->assertJsonPath('body.included_bookings', 1)
        ->assertJsonPath('body.settlement_rate', 1)
        ->assertJsonPath('body.settled_value', 60);
});

it('reports acceptance and booking as separate rates', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    bookedRecommendation($hotel);

    [$accepted] = wp5Recommendation($hotel);
    app(RecommendationOutcomeService::class)->record(
        $accepted, OutcomeType::ACCEPTED, AttributionMethod::CONVERSATIONAL, attributes: ['confidence' => 0.9]
    );

    // 2 delivered; 2 reached acceptance (booked counts); 1 booked.
    conversionReport($admin)->assertOk()
        ->assertJsonPath('body.delivered', 2)
        ->assertJsonPath('body.accepted', 2)
        ->assertJsonPath('body.booked', 1)
        ->assertJsonPath('body.acceptance_rate', 1)
        ->assertJsonPath('body.booking_rate', 0.5);
});

it('computes the fulfilment gap between accepted and booked', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    bookedRecommendation($hotel);

    foreach (range(1, 2) as $ignored) {
        [$accepted] = wp5Recommendation($hotel);
        app(RecommendationOutcomeService::class)->record(
            $accepted, OutcomeType::ACCEPTED, AttributionMethod::CONVERSATIONAL, attributes: ['confidence' => 0.9]
        );
    }

    $response = conversionReport($admin)->assertOk();

    // Two guests agreed and never ended up with a booking. That is a desk
    // that was closed or a price that did not match — not noise.
    $response->assertJsonPath('body.fulfilment_gap', 2);
    expect($response->json('body.notes'))->toContain('2 accepted guests produced no booking');
});

it('flags a zero realisation rate as possibly unrecorded rather than silent', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    bookedRecommendation($hotel);

    $response = conversionReport($admin)->assertOk();

    // Staff can now record attendance, so 0 is a real measurement — but it
    // could still mean the outlet is not using the endpoint, and the report
    // says so rather than letting the number speak for itself.
    $response->assertJsonPath('body.realisation_rate', 0);
    expect($response->json('body.notes'))->toContain('may mean attendance is not being recorded');
});

it('reports realisation rate as null when there is nothing to divide by', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    wp5Recommendation($hotel);

    conversionReport($admin)->assertOk()->assertJsonPath('body.realisation_rate', null);
});

it('reports a realisation rate once a booking is realised', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $booking] = bookedRecommendation($hotel);

    app(BookingService::class)->realise($booking);

    conversionReport($admin)->assertOk()->assertJsonPath('body.realisation_rate', 1);
});

it('reports the attribution split across all four methods', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    bookedRecommendation($hotel);   // direct

    [$inferredRec, $guest, $activity] = wp5Recommendation($hotel);
    $booking = wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);
    app(RecommendationOutcomeService::class)->record(
        $inferredRec, OutcomeType::BOOKED, AttributionMethod::INFERRED, $booking
    );

    $response = conversionReport($admin)->assertOk();

    $response->assertJsonPath('body.attribution.direct', 1)
        ->assertJsonPath('body.attribution.inferred', 1)
        // Half of it is a guess, and the report says so on its face.
        ->assertJsonPath('body.attribution.direct_share', 0.5);

    expect($response->json('body.notes'))->toContain('50% of attributed bookings are inferred');
});

it('reports the weakest evidence level present, not an average', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    bookedRecommendation($hotel);

    conversionReport($admin)->assertOk()->assertJsonPath('body.evidence_level', 'L1');

    // One inferred row drags the whole figure to L2 — never averaged.
    [$inferredRec, $guest, $activity] = wp5Recommendation($hotel);
    $booking = wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);
    app(RecommendationOutcomeService::class)->record(
        $inferredRec, OutcomeType::BOOKED, AttributionMethod::INFERRED, $booking
    );

    conversionReport($admin)->assertOk()->assertJsonPath('body.evidence_level', 'L2');
});

it('never merges expected value with settled value', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $booking] = bookedRecommendation($hotel, ['charge_model' => ChargeModel::PAY_ON_SITE->value]);

    // Committed 60, only 25 actually arrived.
    app(BookingService::class)->linkSettlement($booking, wp5Transaction($hotel, ['line_total' => 25]));

    conversionReport($admin)->assertOk()
        ->assertJsonPath('body.expected_value', 60)
        ->assertJsonPath('body.settled_value', 25);
});

it('does not sum settled value across currencies', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    [, $usd] = bookedRecommendation($hotel);
    [, $eur] = bookedRecommendation($hotel, ['currency' => 'EUR']);

    app(BookingService::class)->linkSettlement($usd, wp5Transaction($hotel, ['currency' => 'USD', 'line_total' => 60]));
    app(BookingService::class)->linkSettlement($eur, wp5Transaction($hotel, ['currency' => 'EUR', 'line_total' => 40]));

    $response = conversionReport($admin)->assertOk();

    // 60 USD plus 40 EUR is not 100 of anything.
    $response->assertJsonPath('body.settled_value', null)
        ->assertJsonPath('body.settled_value_by_currency.USD', 60)
        ->assertJsonPath('body.settled_value_by_currency.EUR', 40);
});

it('treats a not_delivered recommendation as undelivered, not as a refusal', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation] = wp5Recommendation($hotel);
    wp5Recommendation($hotel);

    app(RecommendationOutcomeService::class)->record(
        $recommendation, OutcomeType::NOT_DELIVERED, AttributionMethod::STAFF
    );

    // 2 made, 1 never reached the guest → 1 delivered. Conversion is measured
    // against what was actually offered.
    conversionReport($admin)->assertOk()
        ->assertJsonPath('body.recommendations_made', 2)
        ->assertJsonPath('body.not_delivered', 1)
        ->assertJsonPath('body.delivered', 1)
        ->assertJsonPath('body.declined', 0);
});

it('scopes the conversion report to the caller hotel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$otherAdmin, $otherHotel] = wp5AdminWithHotel();

    wp5Recommendation($hotel);
    wp5Recommendation($otherHotel);
    wp5Recommendation($otherHotel);

    conversionReport($admin)->assertOk()->assertJsonPath('body.recommendations_made', 1);
    conversionReport($otherAdmin)->assertOk()->assertJsonPath('body.recommendations_made', 2);
});

it('blocks a non-admin from the conversion report', function () {
    [, $hotel] = wp5AdminWithHotel();
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);

    conversionReport($worker)->assertStatus(403);
});
