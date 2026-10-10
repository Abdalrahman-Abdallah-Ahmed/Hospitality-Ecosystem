<?php

use App\Enums\RecommendationStatus;
use App\Models\EventLog;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * SPEC-071 FR-006a: changing what a guest would be offered sends an approved
 * recommendation back for review; ranking changes never touch approval.
 */
it('sends an approved recommendation back for review when its content changes', function (string $field) {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'approved', ['reviewed_at' => now()]);
    $recommendation->forceFill(['reviewed_by_user_id' => rapAdmin($hotel)->id, 'reviewed_at' => now()])->saveQuietly();

    $value = match ($field) {
        'reason' => 'A better reason',
        'activity_id' => rapActivity($hotel, 'Kayaking')->id,
        'reservation_id' => rapInHouseStay($hotel)[1]->id,
    };

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/recommendation/{$recommendation->id}", [$field => $value])
        ->assertOk()
        ->assertJsonPath('body.status', 'pending_approval');

    expect($recommendation->fresh())
        ->status->toBe(RecommendationStatus::PENDING_APPROVAL)
        ->reviewed_by_user_id->toBeNull()
        ->reviewed_at->toBeNull()
        ->{$field}->toBe($value)
        ->and(EventLog::where('subject_id', $recommendation->id)->where('event_type', 'recommendation.approval_reset')->count())->toBe(1);
})->with(['reason', 'activity_id', 'reservation_id']);

it('keeps an approval when only the ranking changes', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'approved');

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/recommendation/{$recommendation->id}", ['priority' => 5, 'predicted_confidence' => 0.4])
        ->assertOk();

    expect($recommendation->fresh())
        ->status->toBe(RecommendationStatus::APPROVED)
        ->priority->toBe(5);
});

it('refuses to change what an offered recommendation offered', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'sent', ['delivered_at' => now()]);

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/recommendation/{$recommendation->id}", ['reason' => 'Rewritten'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'An offered or closed recommendation cannot change its activity, reason or reservation.');

    expect($recommendation->fresh()->reason)->toBe('Suits this guest');
});

it('ignores status and review fields in an edit', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/recommendation/{$recommendation->id}", [
        'status' => 'approved',
        'reviewed_at' => now()->toDateTimeString(),
        'priority' => 2,
    ])->assertOk();

    expect($recommendation->fresh())
        ->status->toBe(RecommendationStatus::PENDING_APPROVAL)
        ->reviewed_at->toBeNull();
});

it('refuses an edit when a pitch staged the recommendation after it was loaded', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $stale = rapRecommendation($reservation, rapActivity($hotel), 'approved');
    // A guest turn stages it meanwhile.
    Recommendation::whereKey($stale->id)->update(['pitch_decision_id' => PitchDecision::create([
        'hotel_id' => $hotel->id, 'guest_id' => $reservation->guest_id, 'eligible' => true, 'gates' => [], 'rules_version' => '2.0', 'decided_at' => now(),
    ])->id]);

    $other = rapActivity($hotel, 'Other');

    expect(fn () => app(RecommendationApprovalService::class)->applyEdit($stale, ['activity_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'An offered or closed recommendation');
    expect($stale->fresh()->status)->toBe(RecommendationStatus::APPROVED);
});
