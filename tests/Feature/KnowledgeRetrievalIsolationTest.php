<?php

use App\Ai\Tools\KnowledgeSearchTool;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

/**
 * A unit vector at $angle radians from the query (which points down the
 * first axis), leaning towards axis $towards.
 */
function knVector(float $angle, int $towards = 1): array
{
    $vector = array_fill(0, ChunkSynchronizer::DIMENSIONS, 0.0);
    $vector[0] = cos($angle);
    $vector[$towards] = sin($angle);

    return $vector;
}

/**
 * A published source with exactly one passage at a chosen distance from the
 * query, written without the observer so the vector is the one we placed.
 */
function knPlacedPassage(?Hotel $hotel, string $title, string $text, float $angle, int $towards = 1): KnowledgeBaseArticle
{
    $article = KnowledgeBaseArticle::withoutEvents(fn () => KnowledgeBaseArticle::create([
        'hotel_id' => $hotel?->id,
        'title' => $title,
        'content' => $text,
        'status' => 'published',
    ]));

    KnowledgeChunk::create([
        'chunkable_type' => $article->getMorphClass(),
        'chunkable_id' => $article->id,
        'hotel_id' => $hotel?->id,
        'content' => $text,
        'metadata' => ['source_type' => 'article', 'location' => null],
        'embedding' => knVector($angle, $towards),
    ]);

    return $article;
}

beforeEach(function () {
    Embeddings::fake(fn ($prompt) => array_fill(0, count($prompt->inputs), knVector(0)));
});

it('lists the hotel own rule first even when the general passage is closer to the question', function () {
    [, $hotelA] = knHotel();
    knPlacedPassage(null, 'Standard Check-out', 'Standard checkout time is 12:00.', 0.05);
    knPlacedPassage($hotelA, 'House Rules', 'Checkout is at 11:00.', 0.3);

    $results = json_decode(knSearch($hotelA), true);

    expect($results)->toHaveCount(2);
    expect($results[0])->rank->toBe(1)->scope->toBe('hotel')->content->toBe('Checkout is at 11:00.');
    expect($results[1])->rank->toBe(2)->scope->toBe('general');
});

it('keeps every hotel to its own knowledge plus general knowledge, from requests and from jobs', function () {
    [, $hotelA] = knHotel();
    [, $hotelB] = knHotel();
    [, $hotelC] = knHotel();
    knPlacedPassage(null, 'Standard Check-out', 'Standard checkout time is 12:00.', 0.1, 1);
    knPlacedPassage($hotelA, 'A rules', 'Hotel A: checkout is at 11:00.', 0.1, 2);
    knPlacedPassage($hotelC, 'C rules', 'Hotel C: breakfast from 07:00.', 0.1, 3);

    $contents = fn (Hotel $hotel) => collect(json_decode(knSearch($hotel), true))->pluck('content')->all();

    expect($contents($hotelA))->toBe(['Hotel A: checkout is at 11:00.', 'Standard checkout time is 12:00.']);
    expect($contents($hotelB))->toBe(['Standard checkout time is 12:00.']);
    expect($contents($hotelC))->toBe(['Hotel C: breakfast from 07:00.', 'Standard checkout time is 12:00.']);

    // Background work: no request, and a tenant context set for another hotel.
    $fromJob = TenantContext::runForHotel($hotelA->id, fn () => (string) (new KnowledgeSearchTool($hotelB))->handle(new Request(['query' => 'Hotel A: checkout is at 11:00.'])));
    expect($fromJob)->not->toContain('Hotel A');

    $noContext = TenantContext::withoutScope(fn () => (string) (new KnowledgeSearchTool($hotelB))->handle(new Request(['query' => 'checkout'])));
    expect($noContext)->not->toContain('Hotel A')->not->toContain('Hotel C');
});

it('returns at most five hotel and three general passages', function () {
    [, $hotel] = knHotel();

    foreach (range(1, 7) as $i) {
        knPlacedPassage($hotel, "Hotel {$i}", "Hotel passage {$i}", 0.01 * $i, $i);
        knPlacedPassage(null, "General {$i}", "General passage {$i}", 0.01 * $i, $i + 10);
    }

    $results = collect(json_decode(knSearch($hotel), true));

    expect($results->where('scope', 'hotel'))->toHaveCount(5);
    expect($results->where('scope', 'general'))->toHaveCount(3);
    expect($results->pluck('scope')->all())->toBe(['hotel', 'hotel', 'hotel', 'hotel', 'hotel', 'general', 'general', 'general']);
});

it('falls back to the general rule once the hotel rule is switched off', function () {
    knFakeEmbeddings();
    [, $hotel] = knHotel();
    $rules = knDocument($hotel, ['title' => 'House Rules'], [['location' => 'Page 1', 'text' => 'Checkout is at 11:00.']]);
    knDocument(null, ['title' => 'Standard'], [['location' => 'Page 1', 'text' => 'Standard checkout time is 12:00.']]);

    ChunkSynchronizer::setActive($rules, false);

    expect(collect(json_decode(knSearch($hotel), true))->pluck('content')->all())->toBe(['Standard checkout time is 12:00.']);
});

it('deletes a force-deleted hotel knowledge with it instead of turning it global', function () {
    knFakeEmbeddings();
    [, $hotel] = knHotel();
    [, $other] = knHotel();
    knDocument($hotel, ['title' => 'Private rules'], [['location' => 'Page 1', 'text' => 'Staff door code is 1234.']]);
    KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Private', 'content' => 'Private article.', 'status' => 'published']);
    knDocument(null, ['title' => 'Standard'], [['location' => 'Page 1', 'text' => 'Standard checkout time is 12:00.']]);

    $globalChunks = KnowledgeChunk::withoutGlobalScope('hotel')->whereNull('hotel_id')->count();

    $hotel->forceDelete();

    expect(KnowledgeChunk::withoutGlobalScope('hotel')->whereNull('hotel_id')->count())->toBe($globalChunks);
    expect(KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->whereNull('hotel_id')->pluck('title')->all())->toBe(['Standard']);
    expect(KnowledgeBaseArticle::withoutGlobalScope('hotel')->withTrashed()->where('title', 'Private')->exists())->toBeFalse();
    expect(knSearch($other))->not->toContain('door code');
});
