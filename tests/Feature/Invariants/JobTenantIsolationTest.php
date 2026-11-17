<?php

use App\Ai\Agents\RecommendationAgent;
use App\Enums\KnowledgeBaseCategory;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Services\Metering\MeteringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

/*
| Spec 017 (SPEC-102, research R3): a queue worker has no request, so every
| job that works for one hotel must establish that hotel's context itself.
| Jobs that deliberately cross hotels are listed with a reason in
| tests/Inventory/entry_points.php instead.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('runs every hotel-scoped job inside TenantContext::runForHotel', function () {
    $jobs = require base_path('tests/Inventory/entry_points.php');

    foreach ($jobs['jobs'] as $class => $entry) {
        if ($entry['scope'] !== 'hotel') {
            continue;
        }

        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        expect(str_contains($source, 'TenantContext::runForHotel('))
            ->toBeTrue("{$class} is listed as hotel-scoped but never calls TenantContext::runForHotel");
    }
});

it('generates recommendations inside the reservation\'s own hotel (spec 017)', function () {
    $hotelA = cmaHotel();
    cmaHotel();
    [, $reservation] = rapInHouseStay($hotelA);
    $seen = 'not called';

    RecommendationAgent::fake(function () use (&$seen) {
        $seen = TenantContext::hotelIds();

        return 'Done.';
    });

    TenantContext::reset();
    (new GenerateActivityRecommendationsJob($reservation))->handle(app(MeteringService::class));

    expect($seen)->toBe([$hotelA->id])
        // and the worker is handed back unscoped afterwards
        ->and(TenantContext::hotelIds())->toBeNull();
});

it('syncs one hotel\'s article without touching another hotel\'s passages (spec 017)', function () {
    Embeddings::fake();
    $hotelA = cmaHotel();
    $hotelB = cmaHotel();

    $article = fn ($hotel) => KnowledgeBaseArticle::withoutEvents(fn () => KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Breakfast times',
        'content' => str_repeat('Breakfast is served from 7am to 10:30am in the main restaurant. ', 20),
        'category' => KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value,
        'status' => 'published',
    ]));

    $articleB = $article($hotelB);
    (new SyncKnowledgeChunksJob($articleB))->handle(app(MeteringService::class));
    $chunksB = KnowledgeChunk::withoutGlobalScope('hotel')->where('hotel_id', $hotelB->id)->orderBy('id')->get()->map->getAttributes()->all();

    TenantContext::reset();
    (new SyncKnowledgeChunksJob($article($hotelA)))->handle(app(MeteringService::class));

    expect($chunksB)->not->toBe([])
        ->and(KnowledgeChunk::withoutGlobalScope('hotel')->where('hotel_id', $hotelB->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($chunksB)
        ->and(KnowledgeChunk::withoutGlobalScope('hotel')->where('hotel_id', $hotelA->id)->count())->toBeGreaterThan(0)
        ->and(TenantContext::hotelIds())->toBeNull();
});
