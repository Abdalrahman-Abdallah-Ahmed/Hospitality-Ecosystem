<?php

use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
