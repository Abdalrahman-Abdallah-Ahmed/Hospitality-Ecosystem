<?php

use App\Enums\AttributionMethod;
use App\Enums\EvidenceLevel;
use App\Enums\OutcomeType;
use App\Enums\StayStatus;
use App\Jobs\MatchRecommendationOutcomesJob;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\RecommendationOutcome;
use App\Models\Stay;
use App\Services\RecommendationOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Runs the nightly matcher for one hotel and hands back the job so its
 * counters can be asserted on.
 */
function runMatcher(Hotel $hotel): MatchRecommendationOutcomesJob
{
    $job = new MatchRecommendationOutcomesJob($hotel->id);
    $job->handle();

    return $job;
}

it('marks a recommendation booked when a matching booking lands inside the window', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    // The recommendation was made 9 hours ago; the booking is now. Well
    // inside the 72h window, and carrying no recommendation_id of its own.
    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    expect(runMatcher($hotel)->matched)->toBe(1);

    $outcome = wp5Outcome($recommendation);

    expect($outcome->outcome)->toBe(OutcomeType::BOOKED)
        ->and($outcome->attribution_method)->toBe(AttributionMethod::INFERRED)
        // A match is not a cause — the guest may have booked anyway.
        ->and($outcome->evidence_level)->toBe(EvidenceLevel::L2);
});

it('does not match a booking created before the recommendation', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel, [
        'recommended_at' => now()->addHour(),   // recommended *after* the booking
    ]);

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    expect(runMatcher($hotel)->matched)->toBe(0)
        ->and(wp5Outcome($recommendation))->toBeNull();
});

it('does not match outside the attribution window', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel, [
        'recommended_at' => now()->subDays(5),   // ~120h ago, window is 72h
    ]);

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    expect(runMatcher($hotel)->matched)->toBe(0)
        ->and(wp5Outcome($recommendation))->toBeNull();
});

it('respects a shortened attribution window from config', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    config(['recommendations.attribution.window_hours' => 4]);   // the gap is 9h

    expect(runMatcher($hotel)->matched)->toBe(0)
        ->and(wp5Outcome($recommendation))->toBeNull();
});

it('does not match one booking to two recommendations', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$first, $guest, $activity] = wp5Recommendation($hotel);

    // A second recommendation for the same guest and activity, made later.
    $second = $first->replicate(['id']);
    $second->recommended_at = now()->subHours(2);
    $second->save();

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    expect(runMatcher($hotel)->matched)->toBe(1);

    // The nearest preceding recommendation wins; the other stays unmatched.
    expect(wp5Outcome($second)?->outcome)->toBe(OutcomeType::BOOKED)
        ->and(wp5Outcome($first))->toBeNull()
        ->and(RecommendationOutcome::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('skips a booking that already carries a recommendation id', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    // Creating it with the id already writes a DIRECT outcome; the matcher
    // must not touch it again.
    wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
    ]);

    expect(runMatcher($hotel)->matched)->toBe(0)
        ->and(wp5Outcome($recommendation)->attribution_method)->toBe(AttributionMethod::DIRECT);
});

// --- precedence ----------------------------------------------------------

it('never lets inference overwrite a conversational or direct outcome', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    // The guest told us no, in their own words.
    app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DECLINED,
        AttributionMethod::CONVERSATIONAL,
        attributes: ['decline_reason' => 'price', 'confidence' => 0.9],
    );

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    runMatcher($hotel);

    // A guess does not replace what the guest actually said.
    expect(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::DECLINED)
        ->and(wp5Outcome($recommendation)->attribution_method)->toBe(AttributionMethod::CONVERSATIONAL);
});

it('lets a direct booking link overwrite a conversational outcome', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::ACCEPTED,
        AttributionMethod::CONVERSATIONAL,
        attributes: ['confidence' => 0.9],
    );

    // Then the commitment actually materialises. A harder fact wins.
    wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
    ]);

    expect(wp5Outcome($recommendation)->outcome)->toBe(OutcomeType::BOOKED)
        ->and(wp5Outcome($recommendation)->attribution_method)->toBe(AttributionMethod::DIRECT);
});

it('never lets staff capture overwrite a direct booking link', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
    ]);

    app(RecommendationOutcomeService::class)->record(
        $recommendation,
        OutcomeType::DECLINED,
        AttributionMethod::STAFF,
        attributes: ['decline_reason' => 'mistaken entry', 'recorded_by_user_id' => $admin->id],
    );

    expect(wp5Outcome($recommendation)->attribution_method)->toBe(AttributionMethod::DIRECT);
});

// --- expiry --------------------------------------------------------------

it('marks the recommendation expired when the guest departs undecided', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest] = wp5Recommendation($hotel);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => $recommendation->reservation_id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-06',
        'checked_in_at' => '2026-09-01 14:00',
        'checked_out_at' => '2026-09-06 10:00',
        'status' => StayStatus::DEPARTED,
    ]);

    expect(runMatcher($hotel)->expired)->toBe(1);

    $outcome = wp5Outcome($recommendation);

    // Reached purely by elimination — nothing was observed at all, so NONE/L4.
    expect($outcome->outcome)->toBe(OutcomeType::EXPIRED)
        ->and($outcome->attribution_method)->toBe(AttributionMethod::NONE)
        ->and($outcome->evidence_level)->toBe(EvidenceLevel::L4);
});

it('stores the window and matcher version in context for every inferred row', function () {
    [, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);

    runMatcher($hotel);

    // Someone will compare 24h against 72h; without this the rows are unusable.
    expect(wp5Outcome($recommendation)->context)->toBe([
        'attribution_window_hours' => 72,
        'matcher_version' => '1.0',
    ]);
});

it('writes one summary event for a nightly run, not one per match', function () {
    [, $hotel] = wp5AdminWithHotel();

    foreach (range(1, 3) as $ignored) {
        [, $guest, $activity] = wp5Recommendation($hotel);
        wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id]);
    }

    expect(runMatcher($hotel)->matched)->toBe(3);

    expect(EventLog::withoutGlobalScope('hotel')->where('event_type', 'recommendation_outcome.created')->count())->toBe(0)
        ->and(EventLog::withoutGlobalScope('hotel')->where('event_type', 'hotel.recommendation_outcomes_matched')->count())->toBe(1);
});
