<?php

use App\Ai\Tools\KnowledgeSearchTool;
use App\Enums\KnowledgeAudience;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\User;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

/**
 * Fake embeddings are random per call, which would make cosine-similarity
 * filtering unpredictable. Returning the same fixed unit vector for every
 * input keeps every embedding maximally similar to every other, so these
 * tests can isolate the hotel_id scoping behavior instead of relevance ranking.
 */
function fixedUnitEmbedding(int $dimensions): array
{
    $value = 1 / sqrt($dimensions);

    return array_fill(0, $dimensions, $value);
}

beforeEach(function () {
    Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), fixedUnitEmbedding($prompt->dimensions)));
});

function hotelForKnowledgeSearch(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$owner->id,
        'currency' => 'USD',
    ]);
}

it('searches both the hotel own knowledge base and the global knowledge base, excluding other hotels', function () {
    $hotel = hotelForKnowledgeSearch();
    $otherHotel = hotelForKnowledgeSearch();

    KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Airport Transfer FAQ',
        'content' => 'Guests can request airport pickup at least 3 hours before arrival.',
        'status' => 'published',
    ]);

    KnowledgeBaseArticle::create([
        'hotel_id' => null,
        'title' => 'Hospitality Best Practices',
        'content' => 'Always greet guests warmly by name when possible.',
        'status' => 'published',
    ]);

    KnowledgeBaseArticle::create([
        'hotel_id' => $otherHotel->id,
        'title' => 'Unrelated Hotel Policy',
        'content' => 'This content belongs to a completely different hotel.',
        'status' => 'published',
    ]);

    $tool = new KnowledgeSearchTool($hotel);
    $result = (string) $tool->handle(new Request(['query' => 'What time can guests arrive?']));

    expect($result)
        ->toContain('Guests can request airport pickup')
        ->toContain('Always greet guests warmly')
        ->not->toContain('completely different hotel');

    Embeddings::assertGenerated(fn ($prompt) => $prompt->dimensions === 1536);
});

it('reports no relevant results when the knowledge base is empty', function () {
    $hotel = hotelForKnowledgeSearch();

    $tool = new KnowledgeSearchTool($hotel);
    $result = (string) $tool->handle(new Request(['query' => 'anything']));

    expect($result)->toBe('No relevant results found.');
});

it('still returns the shared global knowledge base inside a tenant context', function () {
    $hotel = hotelForKnowledgeSearch();

    KnowledgeBaseArticle::create([
        'hotel_id' => null,
        'title' => 'Hospitality Best Practices',
        'content' => 'Always greet guests warmly by name when possible.',
        'status' => 'published',
    ]);

    // The HTTP advisor and the WhatsApp job both run with the tenant scope
    // set; the scope alone can never match a hotel_id of null.
    $result = TenantContext::runForHotel($hotel->id, fn () => (string) (new KnowledgeSearchTool($hotel))
        ->handle(new Request(['query' => 'How should staff greet guests?'])));

    expect($result)->toContain('Always greet guests warmly');
});

/**
 * A unit vector leaning away from the first axis by $angle radians, towards
 * axis $towards: cosine distance to the first axis is 1 - cos($angle).
 */
function tiltedEmbedding(float $angle, int $towards): array
{
    $embedding = array_fill(0, ChunkSynchronizer::DIMENSIONS, 0.0);
    $embedding[0] = cos($angle);
    $embedding[$towards] = sin($angle);

    return $embedding;
}

it('finds the hotel own chunk even when many closer chunks belong to other hotels', function () {
    $hotel = hotelForKnowledgeSearch();
    $otherHotel = hotelForKnowledgeSearch();

    // The query points straight down the first axis.
    Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), tiltedEmbedding(0, 1)));

    // Eighty other-hotel chunks, each close to the query in its own
    // direction: more than pgvector's default 40 index candidates. Distinct
    // directions matter, because pgvector folds identical vectors into one
    // graph element and they would not fill the candidate list.
    foreach (range(1, 80) as $i) {
        KnowledgeChunk::create([
            'chunkable_type' => 'knowledge_base_article',
            'chunkable_id' => (string) Str::uuid(),
            'hotel_id' => $otherHotel->id,
            'category' => 'policy',
            'content' => "Another hotel's policy {$i}",
            'embedding' => tiltedEmbedding(0.1, $i),
        ]);
    }

    // This hotel's chunk is relevant, but further from the query than all of
    // them. It shares a direction with one of them, so the index graph links
    // to it and a wide enough search can reach it. Its article exists (the
    // tool only cites live sources) but is written without its observer, so
    // the hand-placed vector below is the only one it has.
    $article = KnowledgeBaseArticle::withoutEvents(fn () => KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Checkout',
        'content' => 'Late checkout is free for returning guests.',
        'status' => 'published',
    ]));

    KnowledgeChunk::create([
        'chunkable_type' => $article->getMorphClass(),
        'chunkable_id' => $article->id,
        'hotel_id' => $hotel->id,
        'category' => 'policy',
        'content' => 'Late checkout is free for returning guests.',
        'embedding' => tiltedEmbedding(0.15, 1),
    ]);

    // A table this small would be scanned exactly. Force the HNSW index, as
    // the planner chooses once the table is large.
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_bitmapscan = off');

    $result = (string) (new KnowledgeSearchTool($hotel))->handle(new Request(['query' => 'Is late checkout free?']));

    expect($result)->toContain('Late checkout is free')
        ->not->toContain("Another hotel's policy");
});

// citations (SPEC 008, US2)

it('cites every result with its scope, kind of source, title, location and date', function () {
    $hotel = hotelForKnowledgeSearch();
    $document = knDocument($hotel, ['title' => 'House Rules 2026'], [['location' => 'Page 2', 'text' => 'Checkout is at 11:00.']]);
    KnowledgeBaseArticle::create(['hotel_id' => null, 'title' => 'Standard Check-out', 'content' => 'Standard checkout time is 12:00.', 'status' => 'published']);

    $results = json_decode(knSearch($hotel), true);

    expect($results)->toHaveCount(2);
    expect($results[0])->toBe([
        'rank' => 1,
        'scope' => 'hotel',
        'source_type' => 'document',
        'title' => 'House Rules 2026',
        'location' => 'Page 2',
        'last_updated' => $document->updated_at->toDateString(),
        'content' => 'Checkout is at 11:00.',
    ]);
    expect($results[1])
        ->scope->toBe('general')
        ->source_type->toBe('article')
        ->title->toBe('Standard Check-out')
        ->location->toBeNull();
});

it('cites a renamed document by its new title with no re-index', function () {
    $hotel = hotelForKnowledgeSearch();
    $document = knDocument($hotel, ['title' => 'Old title']);

    $document->update(['title' => 'Pool & Spa Guide']);

    expect(json_decode(knSearch($hotel), true)[0]['title'])->toBe('Pool & Spa Guide');
});

it('never gives a guest the title or location of a general source', function () {
    $hotel = hotelForKnowledgeSearch();
    knDocument($hotel, ['title' => 'House Rules 2026'], [['location' => 'Page 2', 'text' => 'Checkout is at 11:00.']]);
    knDocument(null, ['title' => 'Platform SOP 7 internal'], [['location' => 'Page 9', 'text' => 'Standard checkout time is 12:00.']]);

    $raw = knSearch($hotel, 'checkout', KnowledgeAudience::GUEST);
    $results = json_decode($raw, true);

    expect($results[0])->title->toBe('House Rules 2026')->location->toBe('Page 2')->source_type->toBe('document');
    expect($results[1])->scope->toBe('general')->source_type->toBe('general')->title->toBeNull()->location->toBeNull();
    expect($raw)->not->toContain('Platform SOP')->not->toContain('Page 9');
});

it('never puts file names, ids, hotel ids or storage paths in a result', function () {
    $hotel = hotelForKnowledgeSearch();
    $document = knDocument($hotel);

    $raw = knSearch($hotel);

    expect($raw)
        ->not->toContain($document->original_filename)
        ->not->toContain($document->id)
        ->not->toContain($hotel->id)
        ->not->toContain('knowledge/');
});

it('leaves out inactive, deleted and unpublished sources', function () {
    $hotel = hotelForKnowledgeSearch();
    knDocument($hotel, ['title' => 'Switched off', 'is_active' => false]);
    $deleted = knDocument($hotel, ['title' => 'Deleted']);
    $deleted->delete();
    $draft = KnowledgeBaseArticle::withoutEvents(fn () => KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Draft', 'content' => 'x', 'status' => 'draft']));
    KnowledgeChunk::create(['chunkable_type' => $draft->getMorphClass(), 'chunkable_id' => $draft->id, 'hotel_id' => $hotel->id, 'content' => 'Draft text', 'embedding' => fixedUnitEmbedding(1536)]);

    expect(knSearch($hotel))->toBe('No relevant results found.');
});
