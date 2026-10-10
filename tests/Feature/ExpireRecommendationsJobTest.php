<?php

use App\Enums\RecommendationStatus;
use App\Enums\StayStatus;
use App\Jobs\ExpireRecommendationsJob;
use App\Models\EventLog;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-071 FR-010: recommendations nobody will ever offer are closed once the
 * stay is over.
 */
it('expires waiting and approved recommendations whose stay is over', function (string $how) {
    $hotel = rapHotel();
    [, $reservation, $stay] = rapInHouseStay($hotel);
    $activity = rapActivity($hotel);
    $pending = rapRecommendation($reservation, $activity, 'pending_approval');
    $approved = rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'approved');

    match ($how) {
        'reservation cancelled' => $reservation->forceFill(['status' => 'cancelled'])->saveQuietly(),
        'reservation checked out' => $reservation->forceFill(['status' => 'checked_out'])->saveQuietly(),
        'every stay over' => Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->update(['status' => StayStatus::DEPARTED->value]),
        'departure date passed' => $reservation->forceFill(['departure_date' => now($hotel->timezone)->subDay()->toDateString()])->saveQuietly(),
    };

    (new ExpireRecommendationsJob)->handle();

    expect($pending->fresh()->status)->toBe(RecommendationStatus::EXPIRED)
        ->and($approved->fresh()->status)->toBe(RecommendationStatus::EXPIRED)
        ->and(EventLog::where('event_type', 'recommendation.expired')->whereIn('subject_id', [$pending->id, $approved->id])->count())->toBe(2);
})->with(['reservation cancelled', 'reservation checked out', 'every stay over', 'departure date passed']);

it('leaves offered recommendations and stays still running alone', function () {
    $hotel = rapHotel();
    [, $running] = rapInHouseStay($hotel);
    [, $finished] = rapInHouseStay($hotel);
    $finished->forceFill(['status' => 'checked_out'])->saveQuietly();

    $stillRunning = rapRecommendation($running, rapActivity($hotel), 'approved');
    $delivered = rapRecommendation($finished, rapActivity($hotel, 'Spa'), 'approved', ['delivered_at' => now()]);
    $declined = rapRecommendation($finished, rapActivity($hotel, 'Dive'), 'rejected');

    (new ExpireRecommendationsJob)->handle();

    expect($stillRunning->fresh()->status)->toBe(RecommendationStatus::APPROVED)
        ->and($delivered->fresh()->status)->toBe(RecommendationStatus::APPROVED)
        ->and($declined->fresh()->status)->toBe(RecommendationStatus::REJECTED);
});

it('uses each hotel\'s own date', function () {
    // 22:30 UTC on the 10th is already the 11th in Riyadh (UTC+3).
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(22, 30));
    $riyadh = rapHotel('Asia/Riyadh');
    $utc = rapHotel('UTC');
    [, $inRiyadh] = rapInHouseStay($riyadh);
    [, $inUtc] = rapInHouseStay($utc);
    $inRiyadh->forceFill(['departure_date' => '2026-10-10'])->saveQuietly();
    $inUtc->forceFill(['departure_date' => '2026-10-10'])->saveQuietly();
    $riyadhRecommendation = rapRecommendation($inRiyadh, rapActivity($riyadh), 'approved');
    $utcRecommendation = rapRecommendation($inUtc, rapActivity($utc), 'approved');

    (new ExpireRecommendationsJob)->handle();

    expect($riyadhRecommendation->fresh()->status)->toBe(RecommendationStatus::EXPIRED)
        ->and($utcRecommendation->fresh()->status)->toBe(RecommendationStatus::APPROVED);
});
