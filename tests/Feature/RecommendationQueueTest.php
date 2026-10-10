<?php

use App\Enums\RecommendationStatus;
use App\Models\EventLog;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * SPEC-071 FR-008 / FR-009: the approval queue at hotel volume.
 */
it('filters and sorts the queue', function () {
    $hotel = rapHotel();
    [$early, $earlyReservation] = rapInHouseStay($hotel);
    [, $lateReservation] = rapInHouseStay($hotel);
    $earlyReservation->forceFill(['arrival_date' => '2026-10-12'])->saveQuietly();
    $lateReservation->forceFill(['arrival_date' => '2026-10-20'])->saveQuietly();
    $spa = rapActivity($hotel, 'Spa');
    $a = rapRecommendation($earlyReservation, rapActivity($hotel, 'Dive'), 'pending_approval', ['priority' => 3]);
    $b = rapRecommendation($lateReservation, $spa, 'pending_approval', ['priority' => 1, 'source' => 'conversation']);
    $c = rapRecommendation($lateReservation, rapActivity($hotel, 'Cruise'), 'approved', ['priority' => 2]);
    $admin = rapAdmin($hotel);
    $ids = fn (string $query) => collect(rapRequest($this, $admin, 'GET', '/api/recommendation?'.$query)->assertOk()->json('body.data'))->pluck('id')->all();

    expect($ids('status=pending_approval&sort=arrival_date'))->toBe([$a->id, $b->id])
        ->and($ids('status=pending_approval,approved&sort=-arrival_date'))->toHaveCount(3)
        ->and($ids('status=pending_approval&sort=-arrival_date')[0])->toBe($b->id)
        ->and($ids('sort=priority'))->toBe([$b->id, $c->id, $a->id])
        ->and($ids('source=conversation'))->toBe([$b->id])
        ->and($ids("guest_id={$early->id}"))->toBe([$a->id])
        ->and($ids('arrival_from=2026-10-15&arrival_to=2026-10-25&sort=priority'))->toBe([$b->id, $c->id])
        ->and($ids("activity_id={$spa->id}"))->toBe([$b->id]);

    rapRequest($this, $admin, 'GET', '/api/recommendation?status=maybe')->assertStatus(422);
});

it('decides many at once, each audited, reporting what it skipped', function () {
    $hotel = rapHotel();
    $other = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    [, $otherReservation] = rapInHouseStay($other);
    $pending = collect(range(1, 3))->map(fn (int $i) => rapRecommendation($reservation, rapActivity($hotel, "Activity {$i}"), 'pending_approval'));
    $decided = rapRecommendation($reservation, rapActivity($hotel, 'Done'), 'rejected_by_admin');
    $foreign = rapRecommendation($otherReservation, rapActivity($other), 'pending_approval');

    $response = rapRequest($this, rapAdmin($hotel), 'POST', '/api/recommendations/decide', [
        'action' => 'approve',
        'ids' => [...$pending->pluck('id'), $decided->id, $foreign->id],
    ])->assertOk();

    expect($response->json('body.decided'))->toBe($pending->pluck('id')->all())
        ->and($response->json('body.skipped'))->toBe([
            ['id' => $decided->id, 'reason' => 'already_decided'],
            ['id' => $foreign->id, 'reason' => 'not_found'],
        ])
        ->and(EventLog::where('event_type', 'recommendation.approved')->count())->toBe(3)
        ->and($foreign->fresh()->status)->toBe(RecommendationStatus::PENDING_APPROVAL);
});

it('gives each rejected recommendation the reason', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $first = rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');
    $second = rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'pending_approval');

    rapRequest($this, rapAdmin($hotel), 'POST', '/api/recommendations/decide', ['action' => 'reject', 'ids' => [$first->id, $second->id], 'reason' => 'Season over'])->assertOk();

    expect(Recommendation::whereIn('id', [$first->id, $second->id])->pluck('review_reason')->unique()->all())->toBe(['Season over']);
});

it('refuses a bulk request that is too large, repeats an id, or gives a reason to approve', function (array $payload) {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');

    $payload = array_map(fn ($value) => $value === 'ID' ? $recommendation->id : $value, $payload);
    $payload['ids'] = $payload['ids'] === ['MANY']
        ? array_map(fn () => (string) Str::uuid(), range(1, 101))
        : array_map(fn ($value) => $value === 'ID' ? $recommendation->id : $value, $payload['ids']);

    rapRequest($this, rapAdmin($hotel), 'POST', '/api/recommendations/decide', $payload)->assertStatus(422);
})->with([
    'too many' => [['action' => 'approve', 'ids' => ['MANY']]],
    'repeated id' => [['action' => 'approve', 'ids' => ['ID', 'ID']]],
    'reason on approve' => [['action' => 'approve', 'ids' => ['ID'], 'reason' => 'Nice']],
    'unknown action' => [['action' => 'maybe', 'ids' => ['ID']]],
]);

it('decides 100 at once in under two seconds', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $activity = rapActivity($hotel);
    $ids = collect(range(1, 100))->map(fn () => rapRecommendation($reservation, $activity, 'pending_approval')->id)->all();

    $started = microtime(true);
    rapRequest($this, rapAdmin($hotel), 'POST', '/api/recommendations/decide', ['action' => 'approve', 'ids' => $ids])->assertOk();

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(Recommendation::where('status', 'approved')->count())->toBe(100);
});
