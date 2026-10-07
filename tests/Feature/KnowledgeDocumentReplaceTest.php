<?php

use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
    Queue::fake();
});

/**
 * @return array{0: User, 1: Hotel, 2: KnowledgeDocument}
 */
function knIndexedDocument($test, string $fixture = 'plain.txt'): array
{
    [$admin, $hotel] = knHotel();

    $id = $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload($fixture), 'title' => 'Front desk notes'], ['Accept' => 'application/json'])
        ->assertCreated()->json('body.id');

    app()->call([new IndexKnowledgeDocumentJob($id), 'handle']);

    return [$admin, $hotel, KnowledgeDocument::withoutGlobalScope('hotel')->findOrFail($id)];
}

function knReplace($test, $admin, KnowledgeDocument $document, string $fixture)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post("/api/knowledge-documents/{$document->id}/file", ['file' => knUpload($fixture)], ['Accept' => 'application/json']);
}

it('keeps the current version searchable until the replacement is indexed, then swaps it in', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    $oldPath = $document->path;

    knReplace($this, $admin, $document, 'notes.md')
        ->assertStatus(202)
        ->assertJsonPath('body.has_pending_replacement', true)
        ->assertJsonPath('body.status', 'uploaded');

    Queue::assertPushed(IndexKnowledgeDocumentJob::class, fn ($job) => $job->documentId === $document->id && $job->mode === 'extract');
    expect(knSearch($hotel))->toContain('Reception is open 24 hours');

    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    $fresh = $document->fresh();
    expect($fresh)
        ->status->value->toBe('indexed')
        ->original_filename->toBe('notes.md')
        ->mime_type->toBe('text/markdown')
        ->pending_path->toBeNull()
        ->pending_content_hash->toBeNull()
        ->content_hash->toBe(hash_file('sha256', knFixturePath('notes.md')));
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($fresh->path);
    expect(knSearch($hotel))->toContain('Parking is free')->not->toContain('Reception is open');
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.replaced']);
});

it('drops staff corrections when the file is replaced', function () {
    [$admin, , $document] = knIndexedDocument($this);
    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", ['segments' => [['location' => 'Document', 'text' => 'Corrected text.']]])->assertStatus(202);

    knReplace($this, $admin, $document, 'notes.md')->assertStatus(202);
    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    expect($document->fresh())
        ->content_source->value->toBe('extracted')
        ->corrected_at->toBeNull()
        ->corrected_by->toBeNull();
});

it('keeps the current version live when the replacement cannot be read, and discards the broken file', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    $oldPath = $document->path;

    knReplace($this, $admin, $document, 'corrupt.pdf')->assertStatus(202);
    $pendingPath = $document->fresh()->pending_path;
    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    $fresh = $document->fresh();
    expect($fresh)
        ->status->value->toBe('failed')
        ->failure_code->value->toBe('corrupt')
        ->path->toBe($oldPath)
        ->pending_path->toBeNull()
        ->pending_content_hash->toBeNull();
    Storage::disk('local')->assertExists($oldPath);
    Storage::disk('local')->assertMissing($pendingPath);
    expect(knSearch($hotel))->toContain('Reception is open 24 hours');
});

it('goes back to the live version after a failed replacement: re-index, correct and rebuild all work again', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    knReplace($this, $admin, $document, 'corrupt.pdf')->assertStatus(202);
    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    Queue::fake();
    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$document->id}/reindex")->assertStatus(202);
    Queue::assertPushed(IndexKnowledgeDocumentJob::class, fn ($job) => $job->mode === 'from_segments');

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", ['segments' => [['location' => 'Document', 'text' => 'Reception closes at midnight.']]])->assertStatus(202);
    app()->call([new IndexKnowledgeDocumentJob($document->id, 'from_segments'), 'handle']);

    expect($document->fresh())->status->value->toBe('indexed');
    expect(knSearch($hotel))->toContain('Reception closes at midnight.');
});

it('indexes the stored text, not a pending replacement, when asked for a stored-text run', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    knReplace($this, $admin, $document, 'notes.md')->assertStatus(202);

    app()->call([new IndexKnowledgeDocumentJob($document->id, 'from_segments'), 'handle']);

    expect($document->fresh())->pending_path->not->toBeNull()->original_filename->toBe('plain.txt');
    expect(knSearch($hotel))->toContain('Reception is open 24 hours');
});

it('refuses a replacement identical to another live document', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    $other = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('notes.md'), 'title' => 'Guest notes'], ['Accept' => 'application/json'])
        ->assertCreated()->json('body.id');

    knReplace($this, $admin, $document, 'notes.md')
        ->assertStatus(409)
        ->assertJsonPath('body.existing.id', $other);
});

it('fails as a duplicate, keeping the live version, when the replacement hash is taken while it is processed', function () {
    [$admin, $hotel, $document] = knIndexedDocument($this);
    knReplace($this, $admin, $document, 'notes.md')->assertStatus(202);

    // Another document with the same bytes appears before the swap.
    KnowledgeDocument::withoutGlobalScope('hotel')->create([
        'hotel_id' => $hotel->id,
        'title' => 'Same bytes',
        'original_filename' => 'copy.md',
        'disk' => 'local',
        'path' => 'knowledge/x/copy.md',
        'mime_type' => 'text/markdown',
        'size' => 1,
        'content_hash' => hash_file('sha256', knFixturePath('notes.md')),
    ]);

    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    expect($document->fresh())->status->value->toBe('failed')->failure_code->value->toBe('duplicate');
    expect(knSearch($hotel))->toContain('Reception is open 24 hours');
});

it('replaces the file of a failed document and clears its failure reason', function () {
    [$admin, , $document] = knIndexedDocument($this, 'protected.pdf');
    expect($document->fresh()->failure_code->value)->toBe('encrypted');

    knReplace($this, $admin, $document, 'plain.txt')->assertStatus(202)->assertJsonPath('body.failure_code', null);
    app()->call([new IndexKnowledgeDocumentJob($document->id), 'handle']);

    expect($document->fresh())->status->value->toBe('indexed')->failure_code->toBeNull();
});
