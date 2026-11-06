<?php

use App\Jobs\IndexKnowledgeDocumentJob;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeIndexRebuild;
use App\Support\Knowledge\Extraction\ExtractorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
});

/**
 * One hotel with a document (corrected), an article and a policy; a second
 * hotel with a document; one global document and one global article.
 *
 * @return array<string, mixed>
 */
function knRebuildFixture(): array
{
    [, $hotel] = knHotel();
    [, $other] = knHotel();

    $corrected = knDocument($hotel, [
        'title' => 'House Rules',
        'content_source' => 'corrected',
        'corrected_at' => now(),
    ], [['location' => 'Page 1', 'text' => 'Corrected: checkout is at 10:30.']]);
    $article = KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Welcome', 'content' => 'Welcome drink on arrival.', 'status' => 'published']);
    $policy = HotelPolicy::create(['hotel_id' => $hotel->id, 'title' => 'Pets', 'content' => 'Small pets welcome.', 'is_active' => true]);
    $otherDocument = knDocument($other, ['title' => 'Other rules']);
    $globalDocument = knDocument(null, ['title' => 'Standard'], [['location' => 'Page 1', 'text' => 'Standard checkout time is 12:00.']]);
    $globalArticle = KnowledgeBaseArticle::create(['hotel_id' => null, 'title' => 'Greeting', 'content' => 'Greet guests by name.', 'status' => 'published']);

    return compact('hotel', 'other', 'corrected', 'article', 'policy', 'otherDocument', 'globalDocument', 'globalArticle');
}

function knChunkSet(): array
{
    return KnowledgeChunk::withoutGlobalScope('hotel')->get()
        ->groupBy('chunkable_id')
        ->map(fn ($chunks) => $chunks->pluck('content')->sort()->values()->all())
        ->sortKeys()
        ->all();
}

it('rebuilds one hotel from stored text, keeping corrections, with nothing read from files', function () {
    $f = knRebuildFixture();
    $before = knChunkSet();
    $registry = Mockery::spy(ExtractorRegistry::class);
    app()->instance(ExtractorRegistry::class, $registry);

    $response = knRequest($this, knSuperAdmin(), 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'hotel', 'hotel_id' => $f['hotel']->id])
        ->assertStatus(202)
        ->assertJsonPath('body.scope', 'hotel')
        ->assertJsonPath('body.total', 3);

    $rebuild = KnowledgeIndexRebuild::findOrFail($response->json('body.id'));
    expect($rebuild)->status->value->toBe('completed')->succeeded->toBe(3)->failed->toBe(0)->finished_at->not->toBeNull();

    $registry->shouldNotHaveReceived('for');
    expect(knChunkSet())->toBe($before);
    expect(knSearch($f['hotel']))->toContain('Corrected: checkout is at 10:30.');
    $this->assertDatabaseHas('event_log', ['subject_id' => $rebuild->id, 'event_type' => 'knowledge_index_rebuild.started']);
    expect(DB::table('event_log')->where('subject_id', $rebuild->id)->where('event_type', 'knowledge_index_rebuild.completed')->count())->toBe(1);
});

it('rebuilds everything, or the global knowledge only', function () {
    knRebuildFixture();
    $super = knSuperAdmin();

    knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'all'])->assertStatus(202)->assertJsonPath('body.total', 6);
    knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'global'])->assertStatus(202)->assertJsonPath('body.total', 2);

    expect(KnowledgeIndexRebuild::where('status', 'completed')->count())->toBe(2);
});

it('lists a source that fails, keeps its previous passages, and still completes', function () {
    $f = knRebuildFixture();
    // A document with no stored text has to be read from its file — which is gone.
    $broken = knDocument($f['hotel'], ['title' => 'Lost file']);
    $broken->forceFill(['segments' => null])->saveQuietly();
    $brokenChunks = KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $broken->id)->pluck('id')->all();

    $id = knRequest($this, knSuperAdmin(), 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'hotel', 'hotel_id' => $f['hotel']->id])
        ->assertStatus(202)->json('body.id');

    $rebuild = KnowledgeIndexRebuild::findOrFail($id);
    expect($rebuild)->status->value->toBe('completed')->total->toBe(4)->succeeded->toBe(3)->failed->toBe(1);
    // jsonb stores object keys in its own order.
    expect($rebuild->failures)->toEqual([[
        'source_type' => 'document',
        'source_id' => $broken->id,
        'title' => 'Lost file',
        'reason' => 'file_missing',
    ]]);
    expect(KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $broken->id)->pluck('id')->all())->toBe($brokenChunks);
});

it('still completes when a document is deleted after the rebuild was queued, or its run is superseded', function () {
    $f = knRebuildFixture();
    Queue::fake();

    $id = knRequest($this, knSuperAdmin(), 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'global'])->json('body.id');
    $rebuild = KnowledgeIndexRebuild::findOrFail($id);

    // The global document is deleted before its job runs.
    $f['globalDocument']->delete();
    app()->call([new IndexKnowledgeDocumentJob($f['globalDocument']->id, 'from_segments', $id), 'handle']);

    // The global article's job reports like any other.
    Queue::assertPushed(SyncKnowledgeChunksJob::class, function ($job) {
        app()->call([$job, 'handle']);

        return true;
    });

    expect($rebuild->fresh())->status->value->toBe('completed')->succeeded->toBe(2)->failed->toBe(0);
});

it('still completes when an article or policy is gone by the time its job runs', function () {
    $f = knRebuildFixture();
    Queue::fake();

    $id = knRequest($this, knSuperAdmin(), 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'hotel', 'hotel_id' => $f['hotel']->id])->json('body.id');

    // The article and policy disappear (as with a hotel force-delete
    // cascade) before their queued jobs run.
    DB::table('knowledge_base_articles')->where('id', $f['article']->id)->delete();
    DB::table('hotel_policies')->where('id', $f['policy']->id)->delete();

    foreach (Queue::pushed(SyncKnowledgeChunksJob::class) as $job) {
        app()->call([unserialize(serialize($job)), 'handle']);
    }
    foreach (Queue::pushed(IndexKnowledgeDocumentJob::class) as $job) {
        app()->call([$job, 'handle']);
    }

    expect(KnowledgeIndexRebuild::findOrFail($id))->status->value->toBe('completed')->succeeded->toBe(3);
});

it('validates the scope and needs a hotel only for a hotel rebuild', function () {
    [, $hotel] = knHotel();
    $super = knSuperAdmin();

    knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'everything'])->assertUnprocessable();
    knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'hotel'])->assertUnprocessable();
    knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'all', 'hotel_id' => $hotel->id])->assertUnprocessable();
});

it('shows rebuild progress to the super admin only', function () {
    [$admin] = knHotel();
    $super = knSuperAdmin();
    knRebuildFixture();

    $id = knRequest($this, $super, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'all'])->json('body.id');

    knRequest($this, $super, 'GET', '/api/admin/knowledge/rebuilds')->assertOk()->assertJsonPath('body.data.0.id', $id);
    knRequest($this, $super, 'GET', "/api/admin/knowledge/rebuilds/{$id}")->assertOk()->assertJsonPath('body.requested_by.id', $super->id);

    knRequest($this, $admin, 'POST', '/api/admin/knowledge/rebuilds', ['scope' => 'all'])->assertForbidden();
    knRequest($this, $admin, 'GET', "/api/admin/knowledge/rebuilds/{$id}")->assertForbidden();
});

it('starts a tracked rebuild from the command line', function () {
    $f = knRebuildFixture();

    $this->artisan('knowledge:sync', ['--hotel' => $f['hotel']->id, '--documents' => true])->assertSuccessful();
    $this->artisan('knowledge:sync', ['--global' => true])->assertSuccessful();

    expect(KnowledgeIndexRebuild::orderBy('created_at')->get()->map(fn ($r) => [$r->scope->value, $r->total])->all())
        ->toBe([['hotel', 3], ['global', 1]]);
    expect(KnowledgeDocument::withoutGlobalScope('hotel')->count())->toBe(3);
});
