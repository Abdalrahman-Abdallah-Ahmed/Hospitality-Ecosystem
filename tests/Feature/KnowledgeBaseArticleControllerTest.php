<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Embeddings::fake();
});

function knowledgeBaseArticleApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithOwnedHotelForArticles(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function articleFor(?Hotel $hotel, array $overrides = []): KnowledgeBaseArticle
{
    return KnowledgeBaseArticle::create(array_merge([
        'hotel_id' => $hotel?->id,
        'title' => 'Airport Transfer FAQ',
        'content' => 'Guests can request airport pickup at least 3 hours before arrival.',
    ], $overrides));
}

// store — regular admin (unchanged behavior)

it('creates an article scoped to the caller own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithOwnedHotelForArticles();
    [, $otherHotel] = adminWithOwnedHotelForArticles();

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/knowledge-base-articles', [
            'hotel_id' => $otherHotel->id,
            'title' => 'Airport Transfer FAQ',
            'content' => 'Guests can request airport pickup at least 3 hours before arrival.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);

    expect(KnowledgeBaseArticle::where('hotel_id', $otherHotel->id)->exists())->toBeFalse();
});

it('rejects an admin without an associated hotel from creating an article', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/knowledge-base-articles', [
            'title' => 'Airport Transfer FAQ',
            'content' => 'Guests can request airport pickup at least 3 hours before arrival.',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'You do not belong to any hotel.');
});

// store — super admin (global KB)

it('lets a super admin create a global article with a null hotel_id, ignoring a spoofed hotel_id', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    [, $hotel] = adminWithOwnedHotelForArticles();

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/knowledge-base-articles', [
            'hotel_id' => $hotel->id,
            'title' => 'Hospitality Best Practices',
            'content' => 'Always greet guests by name when possible.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', null);
});

// update — super admin (global KB)

it('lets a super admin update a global article without an owned hotel, leaving hotel_id null', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $article = articleFor(null, ['title' => 'Original']);

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/knowledge-base-articles/{$article->id}", ['title' => 'Updated']);

    $response->assertOk()
        ->assertJsonPath('body.title', 'Updated')
        ->assertJsonPath('body.hotel_id', null);
});

it('lets a super admin update a hotel-specific article without reassigning its hotel_id', function () {
    [, $hotel] = adminWithOwnedHotelForArticles();
    $article = articleFor($hotel, ['title' => 'Original']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/knowledge-base-articles/{$article->id}", ['title' => 'Updated by Super Admin']);

    $response->assertOk()->assertJsonPath('body.title', 'Updated by Super Admin');
    expect($article->fresh()->hotel_id)->toBe($hotel->id);
});

it('lets a super admin delete an article belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForArticles();
    $article = articleFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/knowledge-base-articles/{$article->id}")
        ->assertOk();

    expect(KnowledgeBaseArticle::find($article->id))->toBeNull();
});

// index scoping

it('only lists articles belonging to the admin own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForArticles();
    $mine = articleFor($hotel, ['title' => 'Mine']);

    [, $otherHotel] = adminWithOwnedHotelForArticles();
    articleFor($otherHotel, ['title' => 'Theirs']);

    articleFor(null, ['title' => 'Global']);

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/knowledge-base-articles');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['id'])->toBe($mine->id);
});

it('lists only the global knowledge base for a super admin', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    [, $hotel] = adminWithOwnedHotelForArticles();
    articleFor($hotel, ['title' => 'Hotel Specific']);

    $global = articleFor(null, ['title' => 'Global']);

    $response = $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/knowledge-base-articles');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['id'])->toBe($global->id);
});
