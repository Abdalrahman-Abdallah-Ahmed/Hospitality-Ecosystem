<?php

use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\Knowledge\Extraction\ExtractorRegistry;
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

/**
 * An uploaded and indexed text.pdf (three pages), with the queue left real
 * (sync) so corrections are indexed straight away.
 *
 * @return array{0: User, 1: Hotel, 2: KnowledgeDocument}
 */
function knThreePager($test): array
{
    [$admin, $hotel] = knHotel();

    $id = $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('text.pdf'), 'title' => 'House Rules'], ['Accept' => 'application/json'])
        ->assertCreated()->json('body.id');

    return [$admin, $hotel, KnowledgeDocument::withoutGlobalScope('hotel')->findOrFail($id)];
}

/**
 * @return list<array{location: string, text: string}>
 */
function knCorrected(KnowledgeDocument $document, int $index, string $text): array
{
    $segments = $document->fresh()->segments;
    $segments[$index]['text'] = $text;

    return $segments;
}

it('shows the extracted text per page', function () {
    [$admin, , $document] = knThreePager($this);

    knRequest($this, $admin, 'GET', "/api/knowledge-documents/{$document->id}/text")
        ->assertOk()
        ->assertJsonPath('body.content_source', 'extracted')
        ->assertJsonPath('body.segments.1.location', 'Page 2')
        ->assertJsonPath('body.segments.1.text', "The pool is open until 22:00.\nTowels are available at the pool bar.");
});

it('indexes a correction from the stored text, without reading the file again', function () {
    [$admin, $hotel, $document] = knThreePager($this);
    $registry = Mockery::spy(ExtractorRegistry::class);
    app()->instance(ExtractorRegistry::class, $registry);

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", [
        'segments' => knCorrected($document, 1, 'The pool is open until 21:00.'),
    ])->assertStatus(202)
        ->assertJsonPath('body.content_source', 'corrected')
        ->assertJsonPath('body.corrected_by.id', $admin->id);

    $registry->shouldNotHaveReceived('for');
    expect($document->fresh())->content_source->value->toBe('corrected')->corrected_at->not->toBeNull();
    expect(knSearch($hotel))->toContain('The pool is open until 21:00.')->not->toContain('22:00');
    expect(hash('sha256', Storage::disk('local')->get($document->path)))->toBe(hash_file('sha256', knFixturePath('text.pdf')));
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.text_corrected']);
});

it('refuses a correction that changes, drops or reorders locations', function () {
    [$admin, , $document] = knThreePager($this);
    $segments = $document->segments;

    $put = fn (array $segments) => knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", ['segments' => $segments]);

    $put(array_reverse($segments))->assertUnprocessable()->assertJsonValidationErrors('segments');
    $put(array_slice($segments, 0, 2))->assertUnprocessable();
    $put([...array_slice($segments, 0, 2), ['location' => 'Page 9', 'text' => 'x']])->assertUnprocessable();
});

it('drops an emptied page from the index but keeps its place', function () {
    [$admin, $hotel, $document] = knThreePager($this);

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", [
        'segments' => knCorrected($document, 1, ''),
    ])->assertStatus(202);

    expect($document->fresh()->chunk_count)->toBe(2);
    expect(collect($document->fresh()->segments)->pluck('location')->all())->toBe(['Page 1', 'Page 2', 'Page 3']);
    expect(knSearch($hotel))->not->toContain('pool is open');
});

it('keeps a correction through re-indexing, and drops it when staff discard it', function () {
    [$admin, $hotel, $document] = knThreePager($this);
    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$document->id}/text", [
        'segments' => knCorrected($document, 1, 'The pool is open until 21:00.'),
    ])->assertStatus(202);

    knRequest($this, $admin, 'POST', "/api/knowledge-documents/{$document->id}/reindex")->assertStatus(202);
    expect(knSearch($hotel))->toContain('21:00');

    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$document->id}/text")
        ->assertStatus(202)
        ->assertJsonPath('body.content_source', 'extracted');

    expect($document->fresh())->corrected_at->toBeNull()->corrected_by->toBeNull();
    expect(knSearch($hotel))->toContain('22:00')->not->toContain('21:00');
    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.text_correction_discarded']);
});

it('refuses a correction before there is any extracted text', function () {
    [$admin] = knHotel();
    Queue::fake();
    $id = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes'], ['Accept' => 'application/json'])
        ->json('body.id');

    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$id}/text", ['segments' => [['location' => 'Document', 'text' => 'x']]])
        ->assertUnprocessable();

    Queue::assertPushed(IndexKnowledgeDocumentJob::class, 1);
});
