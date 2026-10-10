<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Enums\ActorKind;
use App\Enums\Permission;
use App\Enums\RecommendationStatus;
use App\Models\EventLog;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('lists recommendations awaiting approval, filtered by guest, room and activity', function () {
    $s = aatSeed();
    rapRecommendation($s['reservation'], rapActivity($s['hotel'], 'Spa'), 'approved');

    $all = aatCall(aatTool($s['admin'], 'GetRecommendationsForReviewTool'));
    $byActivity = aatCall(aatTool($s['admin'], 'GetRecommendationsForReviewTool'), ['activity' => 'alpha tour']);
    $byOtherGuest = aatCall(aatTool($s['admin'], 'GetRecommendationsForReviewTool'), ['guest' => 'Nobody']);
    $approved = aatCall(aatTool($s['admin'], 'GetRecommendationsForReviewTool'), ['status' => 'approved']);

    expect($all)->toMatchArray(['total' => 1, 'partial' => false])
        ->and($all['items'][0])->toMatchArray(['id' => $s['recommendation']->id, 'guest' => 'Alpha Alphason', 'activity' => 'Alpha tour', 'status' => 'pending_approval'])
        ->and($byActivity['total'])->toBe(1)
        ->and($byOtherGuest['total'])->toBe(0)
        ->and($approved['items'][0]['activity'])->toBe('Spa');
});

it('approves one named recommendation after confirmation, audited as the AI acting for the admin', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'DecideRecommendationTool', 'conv-1');
    $args = ['recommendation_id' => $s['recommendation']->id, 'action' => 'approve'];

    expect($tool->inner())->toBeInstanceOf(ConfirmsBeforeRunning::class)
        ->and($tool->inner()->confirmationSummary(new Request($args), 'en'))->toBe('Approve "Alpha tour" for Alpha Alphason.');

    $result = aatCall($tool, $args, 'call-1');

    $event = EventLog::where('subject_id', $s['recommendation']->id)->where('event_type', 'recommendation.approved')->sole();

    expect($result)->toMatchArray(['ok' => true])
        ->and($s['recommendation']->fresh()->status)->toBe(RecommendationStatus::APPROVED)
        ->and($s['recommendation']->fresh()->reviewed_by_user_id)->toBe($s['admin']->id)
        ->and($event->actor_kind)->toBe(ActorKind::AI_AGENT)
        ->and($event->actor_id)->toBe($s['admin']->id)
        ->and($event->context['ai']['tool'] ?? null)->toBe('DecideRecommendationTool');
});

it('refuses without the approve permission, and reports a decided recommendation plainly', function () {
    $s = aatSeed();
    $viewer = aatEmployee($s['hotel'], [Permission::RECOMMENDATIONS_VIEW]);
    $args = ['recommendation_id' => $s['recommendation']->id, 'action' => 'approve'];

    $refused = aatCall(aatTool($viewer, 'DecideRecommendationTool'), $args);

    expect(is_string($refused) ? $refused : json_encode($refused))->toContain('permission')
        ->and($s['recommendation']->fresh()->status)->toBe(RecommendationStatus::PENDING_APPROVAL);

    aatCall(aatTool($s['admin'], 'DecideRecommendationTool'), $args);

    expect(aatCall(aatTool($s['admin'], 'DecideRecommendationTool'), $args))->toStartWith('Not done: This recommendation can no longer be approved');
});

it('cannot decide in bulk: one id per call, and the advisor is told to send bulk work to the queue', function () {
    $s = aatSeed();
    $schema = aatTool($s['admin'], 'DecideRecommendationTool')->schema(new JsonSchemaTypeFactory);

    expect(collect($schema)->map(fn ($type) => $type->toArray()['type'] ?? null)->all())->not->toContain('array')
        ->and((string) (new AdminAdvisorAgent($s['admin']))->instructions())->toContain('point them to the approval')
        ->and(Recommendation::count())->toBe(1);
});
