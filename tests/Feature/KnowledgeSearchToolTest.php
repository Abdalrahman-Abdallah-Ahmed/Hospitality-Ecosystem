<?php

use App\Ai\Tools\KnowledgeSearchTool;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
