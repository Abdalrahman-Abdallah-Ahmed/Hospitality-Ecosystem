<?php

use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\User;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Knowledge\Extraction\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Embeddings::fake();
});

function hotelForKnowledgeChunks(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$owner->id,
        'currency' => 'USD',
    ]);
}

// hotel policies

it('creates embedded knowledge chunks when an active hotel policy is saved', function () {
    $hotel = hotelForKnowledgeChunks();

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'category' => 'recommendation_rules',
        'content' => 'Free cancellation up to 24 hours before arrival.',
        'is_active' => true,
    ]);

    $chunks = KnowledgeChunk::where('chunkable_type', $policy->getMorphClass())
        ->where('chunkable_id', $policy->id)
        ->get();

    expect($chunks)->toHaveCount(1);
    expect($chunks->first())
        ->hotel_id->toBe($hotel->id)
        ->category->toBe('recommendation_rules')
        ->content->toBe($policy->content)
        ->embedding->toHaveCount(1536);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Free cancellation'));
});

it('does not create knowledge chunks for an inactive hotel policy', function () {
    $hotel = hotelForKnowledgeChunks();

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Draft Policy',
        'content' => 'This policy is not live yet.',
        'is_active' => false,
    ]);

    expect($policy->chunks()->count())->toBe(0);
});

it('removes knowledge chunks when a hotel policy is deactivated', function () {
    $hotel = hotelForKnowledgeChunks();

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'content' => 'Free cancellation up to 24 hours before arrival.',
        'is_active' => true,
    ]);

    expect($policy->chunks()->count())->toBe(1);

    $policy->update(['is_active' => false]);

    expect($policy->chunks()->count())->toBe(0);
});

it('deletes knowledge chunks when a hotel policy is deleted', function () {
    $hotel = hotelForKnowledgeChunks();

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'content' => 'Free cancellation up to 24 hours before arrival.',
        'is_active' => true,
    ]);

    expect($policy->chunks()->count())->toBe(1);

    $policy->delete();

    expect(KnowledgeChunk::where('chunkable_id', $policy->id)->count())->toBe(0);
});

// knowledge base articles

it('does not create knowledge chunks for a draft article, but does once published', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Here is everything a guest needs to know before arrival.',
        'status' => 'draft',
    ]);

    expect($article->chunks()->count())->toBe(0);

    $article->update(['status' => 'published']);

    expect($article->chunks()->count())->toBe(1);
});

it('re-syncs knowledge chunks when a published article content changes', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Original content about the hotel.',
        'status' => 'published',
    ]);

    $originalChunkId = $article->chunks()->sole()->id;

    $article->update(['content' => 'Updated content about the hotel.']);

    $chunks = $article->chunks()->get();

    expect($chunks)->toHaveCount(1);
    expect($chunks->first()->id)->not->toBe($originalChunkId);
    expect($chunks->first()->content)->toBe('Updated content about the hotel.');
});

// atomic swap (SPEC 008, R5)

it('keeps an article existing chunks when embedding its new content fails', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Original content about the hotel.',
        'status' => 'published',
    ]);

    $original = $article->chunks()->sole();

    Embeddings::fake(fn () => throw new RuntimeException('provider down'));

    expect(fn () => ChunkSynchronizer::sync($article, 'New content.', $hotel->id, null))
        ->toThrow(RuntimeException::class);

    expect($article->chunks()->sole())
        ->id->toBe($original->id)
        ->content->toBe('Original content about the hotel.');
});

it('labels article and policy chunks with their source type and no location', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Original content about the hotel.',
        'status' => 'published',
    ]);

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'content' => 'Free cancellation up to 24 hours before arrival.',
        'is_active' => true,
    ]);

    expect($article->chunks()->sole()->metadata)
        ->source_type->toBe('article')
        ->location->toBeNull()
        ->title->toBe('Welcome Guide');
    expect($policy->chunks()->sole()->metadata)
        ->source_type->toBe('policy')
        ->location->toBeNull();
});

it('writes nothing when the guard refuses the swap', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Original content about the hotel.',
        'status' => 'published',
    ]);

    $original = $article->chunks()->sole();

    $result = ChunkSynchronizer::syncSegments(
        source: $article,
        segments: [new Segment('Page 1', 'Stale text.')],
        hotelId: $hotel->id,
        category: null,
        guard: fn () => false,
    );

    expect($result)->toBeNull();
    expect($article->chunks()->sole()->id)->toBe($original->id);
});

it('writes inactive chunks for a source deactivated while it was being embedded', function () {
    $hotel = hotelForKnowledgeChunks();

    $policy = HotelPolicy::create([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'content' => 'Free cancellation up to 24 hours before arrival.',
        'is_active' => true,
    ]);

    // Staff switch the policy off while the provider call is in flight.
    Embeddings::fake(function ($prompt) use ($policy) {
        DB::table('hotel_policies')->where('id', $policy->id)->update(['is_active' => false]);

        return array_fill(0, count($prompt->inputs), Embeddings::fakeEmbedding($prompt->dimensions));
    });

    ChunkSynchronizer::sync($policy, 'New wording.', $hotel->id, null);

    expect(KnowledgeChunk::where('chunkable_id', $policy->id)->sole()->is_active)->toBeFalse();
});

it('does not regenerate knowledge chunks on a no-op save', function () {
    $hotel = hotelForKnowledgeChunks();

    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Welcome Guide',
        'content' => 'Original content about the hotel.',
        'status' => 'published',
    ]);

    $originalChunkId = $article->chunks()->sole()->id;

    $article->save();

    expect($article->chunks()->sole()->id)->toBe($originalChunkId);
});
