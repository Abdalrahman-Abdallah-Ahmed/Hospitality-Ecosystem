<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
});

/**
 * Upload through the API; the queue is sync in tests, so the document comes
 * back already processed.
 */
function knIndexed($test, string $fixture, ?array $hotelAdmin = null): KnowledgeDocument
{
    [$admin] = $hotelAdmin ?? knHotel();

    $id = $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload($fixture), 'title' => $fixture], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('body.id');

    return KnowledgeDocument::withoutGlobalScope('hotel')->findOrFail($id);
}

/**
 * @return array<string, string> location => passage text
 */
function knPassages(KnowledgeDocument $document): array
{
    return KnowledgeChunk::withoutGlobalScope('hotel')
        ->where('chunkable_id', $document->id)
        ->orderBy('chunk_index')
        ->get()
        ->mapWithKeys(fn (KnowledgeChunk $chunk) => [$chunk->metadata['location'] => $chunk->content])
        ->all();
}

it('indexes a PDF page by page, without the repeated header', function () {
    $document = knIndexed($this, 'text.pdf');

    expect($document)
        ->status->value->toBe('indexed')
        ->page_count->toBe(3)
        ->chunk_count->toBe(3)
        ->indexed_at->not->toBeNull();

    $passages = knPassages($document);

    expect(array_keys($passages))->toBe(['Page 1', 'Page 2', 'Page 3']);
    expect($passages['Page 2'])->toContain('The pool is open until 22:00.')
        ->not->toContain('House Rules');
    expect(KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $document->id)->first())
        ->hotel_id->toBe($document->hotel_id)
        ->metadata->source_type->toBe('document');
});

it('folds Arabic presentation forms back into ordinary letters', function () {
    $document = knIndexed($this, 'arabic.pdf');

    expect(knPassages($document)['Page 1'])->toBe('مرحبا بكم مرحبا بكم مرحبا بكم');
});

it('splits a Word document into sections and keeps table headings with their values', function () {
    $passages = knPassages(knIndexed($this, 'menu.docx'));

    expect(array_keys($passages))->toBe(['Section: Introduction', 'Section: Breakfast', 'Section: Dinner']);
    expect($passages['Section: Breakfast'])->toContain('06:30 to 10:30');
    expect($passages['Section: Dinner'])
        ->toContain('Dish: Grilled fish; Price: 120 EGP')
        ->toContain('Dish: Lentil soup; Price: 45 EGP');
});

it('keeps spreadsheet column headings with every row and skips empty sheets', function () {
    $document = knIndexed($this, 'shuttle.xlsx');
    $passages = knPassages($document);

    expect(array_keys($passages))->toBe(['Sheet Times, rows 2–4']);
    expect($passages['Sheet Times, rows 2–4'])->toContain('Route: Airport shuttle; Time: 07:00; Gate: Gate B');
    expect($document->page_count)->toBe(2);
});

it('reads a semicolon CSV saved in Windows-1256', function () {
    $passages = knPassages(knIndexed($this, 'prices-1256.csv'));

    expect(array_values($passages))->toBe(["الخدمة: تدليك; السعر: 500\nالخدمة: ساونا; السعر: 200"]);
});

it('splits Markdown by heading and keeps plain text whole', function () {
    expect(array_keys(knPassages(knIndexed($this, 'notes.md'))))->toBe(['Section: Parking', 'Section: Pets']);
    expect(knPassages(knIndexed($this, 'plain.txt')))->toBe(['Document' => "Reception is open 24 hours a day.\nThe spa opens at 09:00."]);
});

it('fails a protected PDF as encrypted, a damaged one as corrupt, and leaves both files untouched', function (string $fixture, string $code) {
    $document = knIndexed($this, $fixture);

    expect($document)
        ->status->value->toBe('failed')
        ->failure_code->value->toBe($code)
        ->chunk_count->toBe(0);
    expect(hash('sha256', Storage::disk('local')->get($document->path)))->toBe(hash_file('sha256', knFixturePath($fixture)));
})->with([
    'protected' => ['protected.pdf', 'encrypted'],
    'corrupt' => ['corrupt.pdf', 'corrupt'],
]);

it('fails a file with no readable text', function () {
    $document = knIndexed($this, 'empty.txt');

    expect($document->status->value)->toBe('failed');
    expect($document->failure_code->value)->toBe('no_text');
});

it('fails a spreadsheet with more rows than the limit', function () {
    config(['knowledge.max_rows' => 2]);

    $document = knIndexed($this, 'shuttle.xlsx');

    expect($document->failure_code?->value)->toBe('too_many_rows');
});

it('records an indexed event and meters the embeddings for the hotel', function () {
    $document = knIndexed($this, 'plain.txt');

    $this->assertDatabaseHas('event_log', ['subject_id' => $document->id, 'event_type' => 'knowledge_document.indexed']);
    $this->assertDatabaseHas('meter_events', ['hotel_id' => $document->hotel_id, 'feature_code' => 'embeddings_generated', 'quantity' => 1]);
});
