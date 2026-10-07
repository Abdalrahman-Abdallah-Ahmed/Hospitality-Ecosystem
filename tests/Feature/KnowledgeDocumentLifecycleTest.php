<?php

use App\Jobs\PurgeDeletedKnowledgeDocumentsJob;
use App\Models\HotelPolicy;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
});

it('removes a document from search the moment it is switched off, and brings it back without re-indexing', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel, ['title' => 'Summer Menu'], [['location' => 'Page 1', 'text' => 'Gazpacho is served all summer.']]);
    Queue::fake();

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('body.is_active', false);
    expect(knSearch($hotel))->toBe('No relevant results found.');

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}", ['is_active' => true])->assertOk();
    expect(knSearch($hotel))->toContain('Gazpacho');

    Queue::assertNothingPushed();
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.deactivated']);
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.activated']);
});

it('changes the category on the passages without re-indexing', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel);
    Queue::fake();

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}", ['category' => 'seasonal_knowledge'])->assertOk();

    expect(KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $document->id)->pluck('category')->unique()->all())->toBe(['seasonal_knowledge']);
    Queue::assertNothingPushed();
});

it('hides a deleted document from search and the list, and restores it within 30 days without re-indexing', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel, ['title' => 'Price list']);
    Queue::fake();

    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$document->id}")->assertOk();

    expect(knSearch($hotel))->toBe('No relevant results found.');
    expect(knRequest($this, $admin, 'GET', '/api/knowledge-documents')->json('body.data'))->toBe([]);

    $this->travel(10)->days();

    $deleted = knRequest($this, $admin, 'GET', '/api/knowledge-documents/deleted')->assertOk()->json('body.data');
    expect($deleted)->toHaveCount(1);
    expect($deleted[0]['id'])->toBe($document->id);
    expect($deleted[0]['restorable_until'])->not->toBeNull();

    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$document->id}/restore")->assertOk()->assertJsonPath('body.deleted_at', null);

    expect(knSearch($hotel))->toContain('Checkout is at 11:00.');
    Queue::assertNothingPushed();
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.deleted']);
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.restored']);
});

it('restores a deleted document that was switched off as still switched off', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel, ['is_active' => false]);

    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$document->id}")->assertOk();
    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$document->id}/restore")->assertOk();

    expect(knSearch($hotel))->toBe('No relevant results found.');
});

it('cannot restore a document deleted more than 30 days ago', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel);
    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$document->id}")->assertOk();

    $this->travel(31)->days();

    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$document->id}/restore")->assertNotFound();
    expect(knRequest($this, $admin, 'GET', '/api/knowledge-documents/deleted')->json('body.data'))->toBe([]);
});

it('purges documents deleted more than 30 days ago, with their passages and files, and keeps their audit trail', function () {
    [$admin, $hotel] = knHotel();
    $old = knDocument($hotel, ['title' => 'Old']);
    $recent = knDocument($hotel, ['title' => 'Recent']);
    Storage::disk('local')->put($old->path, 'old file');
    Storage::disk('local')->put($recent->path, 'recent file');
    $pendingPath = dirname($old->path).'/pending.pdf';
    $old->forceFill(['pending_path' => $pendingPath])->saveQuietly();
    Storage::disk('local')->put($pendingPath, 'pending file');

    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$old->id}")->assertOk();
    $this->travel(2)->days();
    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$recent->id}")->assertOk();
    $this->travel(29)->days();

    (new PurgeDeletedKnowledgeDocumentsJob)->handle();

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->find($old->id))->toBeNull();
    expect(KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $old->id)->count())->toBe(0);
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertMissing($pendingPath);
    $this->assertDatabaseHas('event_log', ['subject_id' => $old->id, 'event_type' => 'knowledge_document.purged']);
    $this->assertDatabaseHas('event_log', ['subject_id' => $old->id, 'event_type' => 'knowledge_document.created']);

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->find($recent->id))->not->toBeNull();
    Storage::disk('local')->assertExists($recent->path);
});

it('refuses to restore a document whose file was uploaded again while it was deleted', function () {
    [$admin] = knHotel();
    Queue::fake();
    $upload = fn () => $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes'], ['Accept' => 'application/json']);

    $first = $upload()->assertCreated()->json('body.id');
    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$first}")->assertOk();
    $second = $upload()->assertCreated()->json('body.id');

    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$first}/restore")
        ->assertStatus(409)
        ->assertJsonPath('body.existing.id', $second);
});

it('accepts a new upload of the same bytes as a deleted document', function () {
    [$admin] = knHotel();
    Queue::fake();
    $upload = fn () => $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes'], ['Accept' => 'application/json']);

    $id = $upload()->assertCreated()->json('body.id');
    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$id}")->assertOk();

    $upload()->assertCreated();
});

it('keeps existing articles and policies searchable, now with citations', function () {
    [, $hotel] = knHotel();
    HotelPolicy::create(['hotel_id' => $hotel->id, 'title' => 'Pet policy', 'content' => 'Small pets are welcome.', 'is_active' => true]);

    expect(json_decode(knSearch($hotel), true)[0])
        ->source_type->toBe('policy')
        ->title->toBe('Pet policy')
        ->scope->toBe('hotel');
});
