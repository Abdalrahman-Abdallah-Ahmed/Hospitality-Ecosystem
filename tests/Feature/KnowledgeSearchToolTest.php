<?php

use App\Ai\Tools\KnowledgeSearchTool;
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
    // to it and a wide enough search can reach it.
    KnowledgeChunk::create([
        'chunkable_type' => 'knowledge_base_article',
        'chunkable_id' => (string) Str::uuid(),
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
