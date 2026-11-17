<?php

use App\Ai\Agents\InsightsAgent;
use App\Http\Resources\AiInsightsResource;
use App\Jobs\CreateAiInsightsJob;
use App\Models\AiInsights;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

/*
| SPEC-090: insights may draw on the hotel's knowledge base and name the
| sources they relied on — only sources the knowledge tool actually returned
| in the run, never another hotel's — and citing knowledge never makes an
| insight look better evidenced than its operational data allows.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    knFakeEmbeddings();
    AiCostContext::reset();
});

afterEach(function () {
    AiCostContext::reset();
});

/**
 * Runs the insights job with the model faked: `$write` receives the agent
 * (so it can search knowledge, as the model would) and returns the insights.
 */
function icGenerate(User $admin, Closure $write): void
{
    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });

    InsightsAgent::fake(function () use (&$agent, $write) {
        return ['insights' => $write($agent)];
    });

    (new CreateAiInsightsJob(['user' => $admin, 'insight_type' => 'general']))->handle(app(MeteringService::class));
}

/**
 * What the knowledge tool showed the model: ref => title.
 *
 * @return array<string, string>
 */
function icSearch(InsightsAgent $agent, string $query = 'late check-out'): array
{
    $results = json_decode((string) $agent->knowledgeTool->handle(new Request(['query' => $query])), true) ?? [];

    return collect($results)->mapWithKeys(fn (array $result) => [$result['ref'] => $result['title']])->all();
}

function icInsight(string $category, string $sourceId, array $citations, string $title = 'Late check-out demand is rising'): array
{
    return ['title' => $title, 'description' => 'Five guests asked this week.', 'category' => $category, 'source_id' => $sourceId, 'citations' => $citations];
}

function icPolicy(Hotel $hotel, string $title = 'Check-in and check-out'): HotelPolicy
{
    return HotelPolicy::create(['hotel_id' => $hotel->id, 'title' => $title, 'content' => 'Late check-out is not offered. Check-out is at 11:00.', 'is_active' => true]);
}

function icList($test, User $admin): array
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->getJson('/api/ai-insights')
        ->assertOk()
        ->json('body.data');
}

it('cites the policy an insight relied on, with its evidence still coming from the record', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);
    [, $reservation] = rapInHouseStay($hotel);
    $policy = icPolicy($hotel);

    icGenerate($admin, function (InsightsAgent $agent) use ($reservation) {
        $shown = icSearch($agent);
        $ref = array_search('Check-in and check-out', $shown, true);

        return [icInsight('reservation', $reservation->id, [$ref])];
    });

    $insight = AiInsights::withoutGlobalScope('hotel')->sole();

    expect($insight->evidence_level->value)->toBe('L2')
        ->and($insight->evidence_sources)->toBe([$reservation->id])
        ->and($insight->citations)->toHaveCount(1)
        ->and($insight->citations[0])->toMatchArray([
            'source_type' => 'policy',
            'source_id' => $policy->id,
            'title' => 'Check-in and check-out',
            'scope' => 'hotel',
            'last_updated' => $policy->updated_at->toDateString(),
        ]);

    expect(icList($this, $admin)[0]['citations'][0])->toMatchArray(['title' => 'Check-in and check-out', 'available' => true]);
});

it('drops a reference the knowledge tool never returned, and logs it', function () {
    $hotel = cmaHotel();
    icPolicy($hotel);
    Log::spy();

    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel) {
        icSearch($agent);

        return [icInsight('general', $hotel->id, ['K1', 'K9'])];
    });

    expect(AiInsights::withoutGlobalScope('hotel')->sole()->citations)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Dropped insight citations') && $context['dropped'] === 1);
});

it('does not cite a source deleted after it was found', function () {
    $hotel = cmaHotel();
    $policy = icPolicy($hotel);

    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel, $policy) {
        icSearch($agent);
        $policy->delete();

        return [icInsight('general', $hotel->id, ['K1'])];
    });

    expect(AiInsights::withoutGlobalScope('hotel')->sole()->citations)->toBe([]);
});

it('collapses repeated references and keeps at most ten citations', function () {
    $hotel = cmaHotel();
    foreach (range(1, 12) as $i) {
        icPolicy($hotel, "Policy {$i}");
    }
    config(['knowledge.search.hotel_limit' => 20]);

    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel) {
        $refs = array_keys(icSearch($agent));

        return [icInsight('general', $hotel->id, ['K1', 'k1', ...$refs])];
    });

    $citations = AiInsights::withoutGlobalScope('hotel')->sole()->citations;

    expect($citations)->toHaveCount(10)
        ->and(collect($citations)->pluck('source_id')->unique())->toHaveCount(10);
});

it('keeps an insight resting on knowledge alone at hypothesis level', function () {
    $hotel = cmaHotel();
    icPolicy($hotel);

    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel) {
        icSearch($agent);

        return [icInsight('knowledge', $hotel->id, ['K1'])];
    });

    $insight = AiInsights::withoutGlobalScope('hotel')->sole();

    expect($insight->category)->toBe('knowledge')
        ->and($insight->evidence_level->value)->toBe('L3')
        ->and($insight->insightable_id)->toBeNull()
        ->and($insight->citations)->toHaveCount(1);
});

it('returns an empty citation list for an insight that used no knowledge', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);

    icGenerate($admin, fn () => [icInsight('general', $hotel->id, [])]);

    expect(AiInsights::withoutGlobalScope('hotel')->sole()->citations)->toBe([])
        ->and(icList($this, $admin)[0]['citations'])->toBe([]);
});

it('marks a citation unavailable once its source is gone, keeping its title', function (string $how) {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);

    $source = match ($how) {
        'policy deleted', 'policy switched off' => icPolicy($hotel),
        'article unpublished' => KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Check-in and check-out', 'content' => 'Late check-out is not offered.', 'status' => 'published']),
        'document switched off' => KnowledgeDocument::create([
            'hotel_id' => $hotel->id, 'title' => 'Check-in and check-out', 'original_filename' => 'rules.pdf',
            'path' => 'knowledge/rules.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'content_hash' => hash('sha256', uniqid()),
        ]),
    };

    // A document's passages come from its indexing pipeline; seed one so the
    // search can find it like any other source.
    if ($source instanceof KnowledgeDocument) {
        DB::table('knowledge_chunks')->insert([
            'id' => (string) Str::uuid7(), 'chunkable_type' => KnowledgeDocument::class, 'chunkable_id' => $source->id,
            'hotel_id' => $hotel->id, 'chunk_index' => 0, 'content' => 'Late check-out is not offered.', 'is_active' => true,
            'embedding' => '['.implode(',', array_fill(0, 1536, 1 / sqrt(1536))).']', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    icGenerate($admin, function (InsightsAgent $agent) use ($hotel) {
        icSearch($agent);

        return [icInsight('general', $hotel->id, ['K1'])];
    });

    match ($how) {
        'policy deleted' => $source->delete(),
        'policy switched off', 'document switched off' => $source->forceFill(['is_active' => false])->saveQuietly(),
        'article unpublished' => $source->forceFill(['status' => 'draft'])->saveQuietly(),
    };

    expect(icList($this, $admin)[0]['citations'][0])->toMatchArray(['title' => 'Check-in and check-out', 'available' => false]);
})->with(['policy deleted', 'policy switched off', 'article unpublished', 'document switched off']);

it('still produces insights when knowledge search fails', function () {
    $hotel = cmaHotel();
    icPolicy($hotel);

    $said = null;
    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel, &$said) {
        // The embedding provider goes down mid-run: the tool says so and the
        // agent carries on from the operational data.
        Embeddings::fake(fn () => throw new RuntimeException('provider down'));
        $said = (string) $agent->knowledgeTool->handle(new Request(['query' => 'late check-out']));

        return [icInsight('general', $hotel->id, ['K1'])];
    });

    expect($said)->toBe('Knowledge search is unavailable right now; continue from the operational data.')
        ->and(AiInsights::withoutGlobalScope('hotel')->sole()->citations)->toBe([]);
});

it('checks citation availability with one query per source type, whatever the page size', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);
    icPolicy($hotel);

    foreach (range(1, 5) as $i) {
        icGenerate($admin, function (InsightsAgent $agent) use ($hotel, $i) {
            icSearch($agent);

            return [icInsight('general', $hotel->id, ['K1'], "Insight {$i}")];
        });
    }

    DB::enableQueryLog();
    icList($this, $admin);
    $policyQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "hotel_policies"'));

    expect($policyQueries)->toHaveCount(1);
});

it('never cites another hotel\'s knowledge, but may cite shared guidance', function () {
    $hotel = cmaHotel();
    $other = cmaHotel();
    icPolicy($other, 'Their check-out policy');
    KnowledgeBaseArticle::create(['hotel_id' => null, 'title' => 'Shared check-out guidance', 'content' => 'Offer late check-out when rooms allow.', 'status' => 'published']);

    $shown = null;
    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel, &$shown) {
        $shown = icSearch($agent);

        return [icInsight('general', $hotel->id, array_keys($shown))];
    });

    $citations = AiInsights::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->sole()->citations;

    expect(array_values($shown))->not->toContain('Their check-out policy')
        ->and(collect($citations)->pluck('title')->all())->toBe(['Shared check-out guidance'])
        ->and($citations[0]['scope'])->toBe('general');
});

it('drops a knowledge insight whose citations all fail to trace', function () {
    $hotel = cmaHotel();
    icPolicy($hotel);

    icGenerate(cmaAdmin($hotel), function (InsightsAgent $agent) use ($hotel) {
        icSearch($agent);

        return [
            icInsight('knowledge', $hotel->id, ['K7'], 'Unsupported claim'),
            icInsight('knowledge', $hotel->id, ['K1'], 'Supported claim'),
        ];
    });

    expect(AiInsights::withoutGlobalScope('hotel')->pluck('title')->all())->toBe(['Supported claim']);
});

it('works out citation availability on its own when no page-wide lookup was passed', function () {
    $hotel = cmaHotel();
    $policy = icPolicy($hotel);
    $insight = AiInsights::create([
        'hotel_id' => $hotel->id, 'title' => 'T', 'description' => 'D', 'category' => 'general', 'insight_type' => 'general',
        'citations' => [['source_type' => 'policy', 'source_id' => $policy->id, 'title' => 'Check-in and check-out', 'location' => null, 'scope' => 'hotel', 'last_updated' => null]],
    ]);

    $shown = (new AiInsightsResource($insight))->toArray(request());

    expect($shown['citations'][0]['available'])->toBeTrue();

    $policy->delete();

    expect((new AiInsightsResource($insight->fresh()))->toArray(request())['citations'][0]['available'])->toBeFalse();
});
