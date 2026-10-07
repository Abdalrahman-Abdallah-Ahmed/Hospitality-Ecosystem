<?php

use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Knowledge\KnowledgeEmbeddingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
});

/**
 * Upload with the queue held, so each test runs the job itself.
 */
function knUploaded($test, string $fixture, ?array $hotelAdmin = null): KnowledgeDocument
{
    [$admin] = $hotelAdmin ?? knHotel();
    Queue::fake();

    $id = $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload($fixture), 'title' => $fixture], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('body.id');

    return KnowledgeDocument::withoutGlobalScope('hotel')->findOrFail($id);
}

function knRunIndex(KnowledgeDocument $document, string $mode = 'extract'): IndexKnowledgeDocumentJob
{
    $job = new IndexKnowledgeDocumentJob($document->id, $mode);
    app()->call([$job, 'handle']);

    return $job;
}

function knChunkIds(KnowledgeDocument $document): array
{
    return KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $document->id)->orderBy('chunk_index')->pluck('id')->all();
}

function knStoredHash(KnowledgeDocument $document): string
{
    return hash('sha256', Storage::disk('local')->get($document->fresh()->path));
}

it('retries transient failures three times, holds back without spending a retry, and allows minutes per run', function () {
    $job = new IndexKnowledgeDocumentJob('id');

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(4)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(300)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('keeps the previous passages when embedding a re-index fails, then marks the document once retries run out', function () {
    $document = knUploaded($this, 'text.pdf');
    knRunIndex($document);
    $before = knChunkIds($document);

    Embeddings::fake(fn () => throw new RuntimeException('provider down'));
    $job = new IndexKnowledgeDocumentJob($document->id, 'from_segments');

    try {
        app()->call([$job, 'handle']);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(KnowledgeEmbeddingFailed::class);
    expect(knChunkIds($document))->toBe($before);

    // What the queue does once the last retry has thrown: failed() runs on a
    // fresh copy of the job, with only the exception to go on.
    (new IndexKnowledgeDocumentJob($document->id, 'from_segments'))->failed($thrown);

    expect($document->fresh())
        ->status->value->toBe('failed')
        ->failure_code->value->toBe('embedding_unavailable');
    expect(knChunkIds($document))->toBe($before);
    expect(knStoredHash($document))->toBe(hash_file('sha256', knFixturePath('text.pdf')));
});

it('records a permanent failure without retrying and never touches the file', function () {
    $document = knUploaded($this, 'protected.pdf');

    knRunIndex($document);

    expect($document->fresh())->status->value->toBe('failed')->failure_code->value->toBe('encrypted');
    expect(knStoredHash($document))->toBe(hash_file('sha256', knFixturePath('protected.pdf')));
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.index_failed', 'reason' => 'encrypted']);
});

it('reports extraction as the failed stage when the run gave up before embedding', function () {
    $document = knUploaded($this, 'text.pdf');
    $document->forceFill(['status' => 'extracting'])->save();

    (new IndexKnowledgeDocumentJob($document->id))->failed(new RuntimeException('vision provider down'));

    expect($document->fresh()->failure_code->value)->toBe('extraction_unavailable');
});

it('does not let an older run that ran out of retries fail a document a newer run has indexed', function () {
    $document = knUploaded($this, 'text.pdf');
    knRunIndex($document);

    (new IndexKnowledgeDocumentJob($document->id))->failed(new KnowledgeEmbeddingFailed(new RuntimeException('late')));

    expect($document->fresh())->status->value->toBe('indexed')->failure_code->toBeNull();
});

it('lets a correction overtake a discard that was queued before it', function () {
    [$admin, $hotel] = knHotel();
    $document = knUploaded($this, 'text.pdf', [$admin, $hotel]);
    knRunIndex($document);
    $segments = $document->fresh()->segments;
    $segments[1]['text'] = 'The pool is open until 21:00.';

    // Discard queues an extract run; a new correction lands before it runs.
    $document->fresh()->forceFill(['segments' => $segments, 'content_source' => 'corrected', 'corrected_at' => now()])->save();

    knRunIndex($document->fresh(), 'extract');
    knRunIndex($document->fresh(), 'from_segments');

    expect($document->fresh())->content_source->value->toBe('corrected');
    expect($document->fresh()->segments[1]['text'])->toBe('The pool is open until 21:00.');
    expect(knSearch($hotel))->toContain('21:00')->not->toContain('22:00');
});

it('does not let a later failure from the queue overwrite a permanent failure reason', function () {
    $document = knUploaded($this, 'protected.pdf');
    $job = knRunIndex($document);

    $job->failed(new RuntimeException('late'));

    expect($document->fresh()->failure_code->value)->toBe('encrypted');
});

it('leaves exactly one set of passages after indexing twice', function () {
    $document = knUploaded($this, 'text.pdf');

    knRunIndex($document);
    knRunIndex($document);
    knRunIndex($document, 'from_segments');

    expect(knChunkIds($document))->toHaveCount(3);
    expect($document->fresh()->chunk_count)->toBe(3);
});

it('discards a run whose input changed while it was embedding', function () {
    $document = knUploaded($this, 'text.pdf');
    knRunIndex($document);
    $before = knChunkIds($document);

    // A correction lands while the provider call is in flight.
    Embeddings::fake(function ($prompt) use ($document) {
        DB::table('knowledge_documents')->where('id', $document->id)->update([
            'content_source' => 'corrected',
            'corrected_at' => now(),
            'segments' => json_encode([['location' => 'Page 1', 'text' => 'Corrected.']]),
        ]);

        return array_fill(0, count($prompt->inputs), Embeddings::fakeEmbedding($prompt->dimensions));
    });

    knRunIndex($document);

    expect(knChunkIds($document))->toBe($before);
});

it('leaves no passages behind for a document deleted while it was being extracted', function () {
    $document = knUploaded($this, 'text.pdf');

    Embeddings::fake(function ($prompt) use ($document) {
        DB::table('knowledge_documents')->where('id', $document->id)->update(['deleted_at' => now()]);

        return array_fill(0, count($prompt->inputs), Embeddings::fakeEmbedding($prompt->dimensions));
    });

    knRunIndex($document);

    expect(knChunkIds($document))->toBe([]);
});

it('fails with file_missing when the stored file is gone, keeping the passages it had', function () {
    $document = knUploaded($this, 'text.pdf');
    knRunIndex($document);
    $before = knChunkIds($document);
    Storage::disk('local')->delete($document->path);

    knRunIndex($document, 'extract');

    expect($document->fresh()->failure_code->value)->toBe('file_missing');
    expect(knChunkIds($document))->toBe($before);
});

it('queues a re-index from the stored text, or from the file when there is none yet', function () {
    [$admin, $hotel] = knHotel();
    $indexed = knUploaded($this, 'text.pdf', [$admin, $hotel]);
    knRunIndex($indexed);
    $failed = knUploaded($this, 'protected.pdf', [$admin, $hotel]);
    knRunIndex($failed);
    Queue::fake();

    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$indexed->id}/reindex")->assertStatus(202);
    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$failed->id}/reindex")->assertStatus(202);

    Queue::assertPushed(IndexKnowledgeDocumentJob::class, fn ($job) => $job->documentId === $indexed->id && $job->mode === 'from_segments');
    Queue::assertPushed(IndexKnowledgeDocumentJob::class, fn ($job) => $job->documentId === $failed->id && $job->mode === 'extract');
    $this->assertDatabaseHas('event_log', ['subject_id' => $indexed->id, 'event_type' => 'knowledge_document.reindex_requested']);
});

it('clears the failure reason when a failed document goes back into the pipeline', function () {
    $document = knUploaded($this, 'protected.pdf');
    knRunIndex($document);
    Storage::disk('local')->put($document->path, file_get_contents(knFixturePath('text.pdf')));

    knRunIndex($document);

    expect($document->fresh())->status->value->toBe('indexed')->failure_code->toBeNull();
});
