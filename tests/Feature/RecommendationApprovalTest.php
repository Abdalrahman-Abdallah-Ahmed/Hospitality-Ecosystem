<?php

use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\CreateRecommendationTool;
use App\Enums\Permission;
use App\Enums\RecommendationSource;
use App\Enums\RecommendationStatus;
use App\Jobs\EvaluateProactiveTriggersJob;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\Metering\MeteringService;
use App\Services\Pitching\PitchRecommendationGenerator;
use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @return array{0: Hotel, 1: Reservation, 2: Recommendation}
 */
function approvalSetup(string $status = 'pending_approval', array $overrides = []): array
{
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);

    return [$hotel, $reservation, rapRecommendation($reservation, rapActivity($hotel), $status, $overrides)];
}

function approvalEvents(Recommendation $recommendation, string $verb): int
{
    return EventLog::where('subject_id', $recommendation->id)->where('event_type', "recommendation.{$verb}")->count();
}

it('approves a pending recommendation and records who and when', function () {
    [$hotel, , $recommendation] = approvalSetup();
    $admin = rapAdmin($hotel);

    rapRequest($this, $admin, 'POST', "/api/recommendation/{$recommendation->id}/approve")
        ->assertOk()
        ->assertJsonPath('body.status', 'approved')
        ->assertJsonPath('body.reviewed_by.id', $admin->id)
        ->assertJsonPath('body.offerable', true);

    expect($recommendation->fresh())
        ->status->toBe(RecommendationStatus::APPROVED)
        ->reviewed_by_user_id->toBe($admin->id)
        ->reviewed_at->not->toBeNull()
        ->and(approvalEvents($recommendation, 'approved'))->toBe(1);
});

it('rejects with a reason, and can withdraw an approval that was never offered', function () {
    [$hotel, $reservation, $pending] = approvalSetup();
    $approved = rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'approved');
    $admin = rapAdmin($hotel);

    rapRequest($this, $admin, 'POST', "/api/recommendation/{$pending->id}/reject", ['reason' => 'Pool closed for maintenance'])
        ->assertOk()
        ->assertJsonPath('body.status', 'rejected_by_admin')
        ->assertJsonPath('body.review_reason', 'Pool closed for maintenance');
    rapRequest($this, $admin, 'POST', "/api/recommendation/{$approved->id}/reject")->assertOk();

    expect($pending->fresh()->status)->toBe(RecommendationStatus::REJECTED_BY_ADMIN)
        ->and($approved->fresh()->status)->toBe(RecommendationStatus::REJECTED_BY_ADMIN)
        ->and(EventLog::where('subject_id', $pending->id)->where('event_type', 'recommendation.rejected_by_admin')->value('reason'))->toBe('Pool closed for maintenance');
});

it('refuses to decide a recommendation that was offered, closed or already decided', function (string $status, array $overrides, string $action) {
    [$hotel, , $recommendation] = approvalSetup($status, $overrides);

    rapRequest($this, rapAdmin($hotel), 'POST', "/api/recommendation/{$recommendation->id}/{$action}")
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'can no longer be'));

    expect($recommendation->fresh()->status->value)->toBe($status)
        ->and(approvalEvents($recommendation, 'approved') + approvalEvents($recommendation, 'rejected_by_admin'))->toBe(0);
})->with([
    'approve an approved one' => ['approved', [], 'approve'],
    'approve a rejected one' => ['rejected_by_admin', [], 'approve'],
    'reject a sent one' => ['sent', [], 'reject'],
    'approve a declined one' => ['rejected', [], 'approve'],
    'reject an expired one' => ['expired', [], 'reject'],
    'reject an approved one already delivered' => ['approved', ['delivered_at' => now()], 'reject'],
]);

it('refuses a rejection reason over 500 characters', function () {
    [$hotel, , $recommendation] = approvalSetup();

    rapRequest($this, rapAdmin($hotel), 'POST', "/api/recommendation/{$recommendation->id}/reject", ['reason' => str_repeat('x', 501)])
        ->assertStatus(422);

    expect($recommendation->fresh()->status)->toBe(RecommendationStatus::PENDING_APPROVAL);
});

it('lets exactly one of two concurrent approvals win', function () {
    [$hotel, , $recommendation] = approvalSetup();
    $service = app(RecommendationApprovalService::class);
    // A second approver who loaded it before the first decided.
    $stale = Recommendation::find($recommendation->id);

    $service->approve($recommendation, rapAdmin($hotel));

    expect(fn () => $service->approve($stale, rapEmployee($hotel, [Permission::RECOMMENDATIONS_APPROVE])))
        ->toThrow(RuntimeException::class, 'can no longer be approved (status: approved)');
    expect($recommendation->fresh()->reviewed_by_user_id)->toBe(rapAdmin($hotel)->id)
        ->and(approvalEvents($recommendation, 'approved'))->toBe(1);
});

it('does not let an approval win over a pitch that staged it first', function () {
    [$hotel, , $recommendation] = approvalSetup('approved');
    $stale = Recommendation::find($recommendation->id);
    $recommendation->forceFill(['delivered_at' => now()])->saveQuietly();

    expect(fn () => app(RecommendationApprovalService::class)->reject($stale, rapAdmin($hotel)))
        ->toThrow(RuntimeException::class);
    expect($recommendation->fresh()->status)->toBe(RecommendationStatus::APPROVED);
});

it('creates generated recommendations as pending approval with their source', function (string $path, RecommendationSource $source) {
    $hotel = rapHotel();
    [, $reservation, $stay] = rapInHouseStay($hotel);
    $activity = rapActivity($hotel);

    // The fake only sees the prompt text, so the agent being prompted is
    // captured from the event, and its own create tool writes the row: the
    // source comes from wherever the caller built the agent.
    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });

    RecommendationAgent::fake(function () use (&$agent, $activity) {
        collect($agent->tools())
            ->first(fn ($tool) => $tool instanceof CreateRecommendationTool)
            ->handle(new Request([
                'activity_id' => $activity->id,
                'reason' => 'Loves the sea',
                'predicted_confidence' => 0.7,
                'priority' => 1,
            ]));

        return 'Done.';
    });

    $path === 'staff'
        ? (new GenerateActivityRecommendationsJob($reservation))->handle(app(MeteringService::class))
        : app(PitchRecommendationGenerator::class)->ensureGenerated($stay);

    expect(Recommendation::where('reservation_id', $reservation->id)->sole())
        ->status->toBe(RecommendationStatus::PENDING_APPROVAL)
        ->source->toBe($source);
})->with([
    'staff request' => ['staff', RecommendationSource::STAFF_REQUEST],
    'guest conversation' => ['conversation', RecommendationSource::CONVERSATION],
]);

it('lets an employee approve only with the permission', function () {
    [$hotel, , $recommendation] = approvalSetup();

    rapRequest($this, rapEmployee($hotel, [Permission::RECOMMENDATIONS_VIEW]), 'POST', "/api/recommendation/{$recommendation->id}/approve")->assertForbidden();
    rapRequest($this, rapEmployee($hotel, [Permission::RECOMMENDATIONS_APPROVE]), 'POST', "/api/recommendation/{$recommendation->id}/approve")->assertOk();
});

it('approves a recommendation whose reservation is gone without failing', function () {
    [$hotel, , $recommendation] = approvalSetup();
    Recommendation::whereKey($recommendation->id)->update(['reservation_id' => null]);

    rapRequest($this, rapAdmin($hotel), 'POST', "/api/recommendation/{$recommendation->id}/approve")->assertOk();

    expect($recommendation->fresh()->status)->toBe(RecommendationStatus::APPROVED);
});

it('evaluates proactive messages once per reservation after a bulk approval', function () {
    Queue::fake();
    [$hotel, $reservation, $first] = approvalSetup();
    $others = collect(range(1, 2))->map(fn (int $i) => rapRecommendation($reservation, rapActivity($hotel, "More {$i}"), 'pending_approval'));

    app(RecommendationApprovalService::class)->decideMany([$first->id, ...$others->pluck('id')], 'approve', rapAdmin($hotel));

    Queue::assertPushed(EvaluateProactiveTriggersJob::class, 1);
});
