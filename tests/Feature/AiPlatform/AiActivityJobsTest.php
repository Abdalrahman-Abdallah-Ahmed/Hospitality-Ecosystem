<?php

use App\Ai\Agents\GuestMemoryAgent;
use App\Ai\Agents\InsightsAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\CreateRecommendationTool;
use App\Jobs\CreateAiInsightsJob;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\AiActivity;
use App\Models\Recommendation;
use App\Services\Metering\MeteringService;
use App\Services\StayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-083 FR-022, FR-026: the AI jobs that write hotel data leave one
 * data_change entry for what they wrote, inside their run.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
});

function jobRows(string $category)
{
    return AiActivity::withoutGlobalScope('hotel')->where('category', $category)->get();
}

it('records the insights a job wrote, in the job\'s run', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);
    InsightsAgent::fake([['insights' => [
        ['title' => 'Busy weekend', 'description' => 'Arrivals peak on Friday.', 'category' => 'general', 'source_id' => $hotel->id],
    ]]]);

    (new CreateAiInsightsJob(['user' => $admin, 'insight_type' => 'general']))->handle(app(MeteringService::class));

    $run = jobRows('ai_run')->sole();
    $change = jobRows('data_change')->sole();

    expect($run->agent->value)->toBe('insights')
        ->and($change->tool)->toBe('CreateAiInsightsJob')
        ->and($change->agent->value)->toBe('insights')
        ->and($change->action)->toBe('ai_insights.created')
        ->and($change->result->value)->toBe('succeeded')
        ->and($change->ai_run_id)->toBe($run->id)
        ->and($change->acted_for_id)->toBe($admin->id)
        ->and($change->hotel_id)->toBe($hotel->id);
});

it('records each recommendation the generation job made once, by the tool, never by the job', function () {
    $hotel = cmaHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $kayak = rapActivity($hotel, 'Kayaking');
    $spa = rapActivity($hotel, 'Spa');

    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });
    $calls = 0;
    RecommendationAgent::fake(function () use (&$agent, &$calls, $kayak, $spa) {
        if ($calls++ === 0) {
            foreach ([$kayak, $spa] as $activity) {
                toolOfClass($agent->tools(), CreateRecommendationTool::class)
                    ->handle(new Request(['activity_id' => $activity->id, 'reason' => 'Fits the party', 'predicted_confidence' => 0.7]));
            }
        }

        return 'Done.';
    });

    (new GenerateActivityRecommendationsJob($reservation))->handle(app(MeteringService::class));

    $changes = jobRows('data_change');
    $run = jobRows('ai_run')->sole();

    expect(Recommendation::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->count())->toBe(2)
        ->and($changes)->toHaveCount(2)
        ->and($changes->pluck('tool')->unique()->all())->toBe(['CreateRecommendationTool'])
        ->and($changes->pluck('agent')->map->value->unique()->all())->toBe(['recommendation'])
        ->and($changes->pluck('ai_run_id')->unique()->all())->toBe([$run->id])
        ->and($run->agent->value)->toBe('recommendation');
});

it('records the text a document extraction wrote', function () {
    Storage::fake('local');
    knFakeEmbeddings();
    [$admin, $hotel] = knHotel();
    Queue::fake();

    $id = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('text.pdf'), 'title' => 'Rules'], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('body.id');

    app()->call([new IndexKnowledgeDocumentJob($id, 'extract'), 'handle']);

    $change = jobRows('data_change')->firstWhere('tool', 'IndexKnowledgeDocumentJob');

    expect($change)->not->toBeNull()
        ->and($change->agent->value)->toBe('document_vision')
        ->and($change->result->value)->toBe('succeeded')
        ->and($change->target_id)->toBe($id)
        ->and($change->hotel_id)->toBe($hotel->id)
        ->and($change->ai_run_id)->not->toBeNull();
});

it('records the memories an extraction wrote, in the extraction\'s run', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = rapInHouseStay($hotel);
    cmaGuestConversation($guest, [['role' => 'user', 'content' => 'I am vegetarian.', 'at' => now()->subHour()]]);
    GuestMemoryAgent::fake([['operations' => [
        ['op' => 'create', 'id' => null, 'category' => 'preference', 'content' => 'Vegetarian.', 'language' => 'en', 'confidence' => 'high'],
    ]]]);

    app(StayLifecycleService::class)->checkOut($stay->fresh());

    $run = jobRows('ai_run')->firstWhere('agent.value', 'guest_memory');
    $change = jobRows('data_change')->firstWhere('tool', 'ExtractGuestMemoriesJob');

    expect($change)->not->toBeNull()
        ->and($change->agent->value)->toBe('guest_memory')
        ->and($change->action)->toBe('guest_memory.created')
        ->and($change->result->value)->toBe('succeeded')
        ->and($change->ai_run_id)->toBe($run->id)
        ->and($change->hotel_id)->toBe($hotel->id);
});
