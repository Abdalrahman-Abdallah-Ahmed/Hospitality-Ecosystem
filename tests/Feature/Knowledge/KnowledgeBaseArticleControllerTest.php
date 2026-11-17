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

// a super admin's own token (spec 028); global articles live in /api/superadmin (SPEC 008)

it('refuses a super admin\'s own token, named hotel or not, and changes nothing', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    [, $hotel] = adminWithOwnedHotelForArticles();
    $article = articleFor($hotel, ['title' => 'Original']);
    $call = fn (string $method, string $uri, array $body = []) => $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->json($method, $uri, $body);

    assertSuperAdminRefused($call('GET', "/api/knowledge-base-articles?hotel_id={$hotel->id}"));
    assertSuperAdminRefused($call('POST', '/api/knowledge-base-articles', ['hotel_id' => $hotel->id, 'title' => 'New', 'content' => 'Text.']));
    assertSuperAdminRefused($call('PUT', "/api/knowledge-base-articles/{$article->id}", ['title' => 'Updated by Super Admin']));
    assertSuperAdminRefused($call('DELETE', "/api/knowledge-base-articles/{$article->id}"));

    expect($article->fresh()->title)->toBe('Original')
        ->and(KnowledgeBaseArticle::withoutGlobalScope('hotel')->count())->toBe(1);
});

// global articles are not reachable from the hotel routes

it('keeps global articles out of reach on the hotel routes', function () {
    [$admin] = adminWithOwnedHotelForArticles();
    $article = articleFor(null, ['title' => 'Original']);

    $call = fn (string $method) => $this->withHeaders(knowledgeBaseArticleApiHeaders())->actingAs($admin, 'sanctum')
        ->json($method, "/api/knowledge-base-articles/{$article->id}", ['title' => 'Updated']);

    $call('GET')->assertNotFound();
    $call('PUT')->assertNotFound();
    $call('DELETE')->assertNotFound();

    expect($article->fresh()->title)->toBe('Original');
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
