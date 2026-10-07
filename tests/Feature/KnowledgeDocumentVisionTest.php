<?php

use App\Ai\Agents\DocumentVisionAgent;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\HotelGroup;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\AiCost\AiSpendCeiling;
use App\Support\Ai\AiCostContext;
use App\Support\Knowledge\Extraction\ExtractionResult;
use App\Support\Knowledge\Extraction\PdfExtractor;
use App\Support\Knowledge\Extraction\Segment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    knFakeEmbeddings();
});

function knVisionUpload($test, string $fixture, $user = null, string $uri = '/api/knowledge-documents'): KnowledgeDocument
{
    $user ??= knHotel()[0];

    $id = $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->post($uri, ['file' => knUpload($fixture), 'title' => $fixture], ['Accept' => 'application/json'])
        ->assertCreated()->json('body.id');

    return KnowledgeDocument::withoutGlobalScope('hotel')->findOrFail($id);
}

function knVisionPassages(KnowledgeDocument $document): array
{
    return KnowledgeChunk::withoutGlobalScope('hotel')->where('chunkable_id', $document->id)->orderBy('chunk_index')
        ->get()->mapWithKeys(fn ($chunk) => [$chunk->metadata['location'] => $chunk->content])->all();
}

function knPagesRead(KnowledgeDocument $document): ?int
{
    return DB::table('meter_events')->where('hotel_id', $document->hotel_id)->where('feature_code', 'knowledge_pages_read')->value('quantity');
}

it('reads the text in an image, with a short description, and meters it', function () {
    knFakeVision([['page' => 1, 'text' => 'SPA OPEN 09:00-21:00', 'description' => 'A printed sign at the spa entrance.']]);

    $document = knVisionUpload($this, 'sign.png');

    expect($document)->status->value->toBe('indexed')->scanned_page_count->toBe(1);
    expect(knVisionPassages($document))->toBe(['Image' => "SPA OPEN 09:00-21:00\n\nA printed sign at the spa entrance."]);
    expect(knPagesRead($document))->toBe(1);
    DocumentVisionAgent::assertPrompted(fn ($prompt) => $prompt->agent->pages === [1] && $prompt->attachments->count() === 1);
});

it('sends only the image-only pages of a PDF, and reads the rest from its text layer', function () {
    knFakeVision([['page' => 2, 'text' => 'Gate B: 07:00, 12:00, 18:00', 'description' => 'A scanned timetable.']]);

    $document = knVisionUpload($this, 'mixed.pdf');

    DocumentVisionAgent::assertPrompted(fn ($prompt) => $prompt->agent->pages === [2]);
    expect(array_keys(knVisionPassages($document)))->toBe(['Page 1', 'Page 2']);
    expect(knVisionPassages($document)['Page 1'])->toContain('The airport shuttle leaves at 07:00 from Gate B.');
    expect($document->scanned_page_count)->toBe(1);
    expect(knPagesRead($document))->toBe(1);
});

it('reads every page of a scanned PDF in one call', function () {
    knFakeVision([
        ['page' => 1, 'text' => 'مرحبا بكم في الفندق', 'description' => 'Page one of a scanned welcome letter.'],
        ['page' => 2, 'text' => 'Breakfast from 07:00', 'description' => 'Page two.'],
    ]);

    $document = knVisionUpload($this, 'scanned.pdf');

    DocumentVisionAgent::assertPrompted(fn ($prompt) => $prompt->agent->pages === [1, 2]);
    expect(array_keys(knVisionPassages($document)))->toBe(['Page 1', 'Page 2']);
    expect(knVisionPassages($document)['Page 1'])->toContain('مرحبا بكم في الفندق');
    expect(knPagesRead($document))->toBe(2);
});

it('never sends a document with a text layer on every page', function () {
    DocumentVisionAgent::fake()->preventStrayPrompts();

    knVisionUpload($this, 'text.pdf');

    DocumentVisionAgent::assertNeverPrompted();
});

it('refuses more than 50 scanned pages before any AI call, and meters nothing', function () {
    DocumentVisionAgent::fake()->preventStrayPrompts();
    app()->instance(PdfExtractor::class, new class extends PdfExtractor
    {
        public function extract(string $absolutePath, string $mimeType): ExtractionResult
        {
            return new ExtractionResult([new Segment('Page 1', 'Cover page text that is long enough.')], 52, range(2, 52));
        }
    });

    $document = knVisionUpload($this, 'text.pdf');

    expect($document)->status->value->toBe('failed')->failure_code->value->toBe('too_many_scanned_pages');
    expect($document->failure_code->message())->toContain('more than 50 scanned pages');
    DocumentVisionAgent::assertNeverPrompted();
    expect(knPagesRead($document))->toBeNull();
});

it('refuses a file too large to send for vision', function () {
    DocumentVisionAgent::fake()->preventStrayPrompts();
    config(['knowledge.vision.max_file_mb' => 0]);

    $document = knVisionUpload($this, 'sign.png');

    expect($document->failure_code->value)->toBe('too_large_for_vision');
    DocumentVisionAgent::assertNeverPrompted();
});

it('fails an image with no readable text', function () {
    knFakeVision([['page' => 1, 'text' => '', 'description' => 'A photo of a sunset.']]);

    $document = knVisionUpload($this, 'sign.png');

    expect($document->failure_code->value)->toBe('no_text');
    expect(knPagesRead($document))->toBeNull();
});

it('meters nothing for a global document, whose cost stays with the platform', function () {
    knFakeVision([['page' => 1, 'text' => 'SPA OPEN', 'description' => 'A sign.']]);

    $document = knVisionUpload($this, 'sign.png', knSuperAdmin(), '/api/admin/knowledge-documents');

    expect($document)->hotel_id->toBeNull()->status->value->toBe('indexed');
    expect(DB::table('meter_events')->where('feature_code', 'knowledge_pages_read')->count())->toBe(0);
});

it('attributes the vision call to the hotel, and stops at the spend ceiling before it is made', function () {
    $attributed = null;
    DocumentVisionAgent::fake(function () use (&$attributed) {
        $attributed = AiCostContext::current()['hotel']?->id;

        return ['pages' => [['page' => 1, 'text' => 'SPA OPEN', 'description' => 'A sign.']]];
    });

    $document = knVisionUpload($this, 'sign.png');
    expect($attributed)->toBe($document->hotel_id);

    $ceiling = Mockery::mock(AiSpendCeiling::class);
    $ceiling->shouldReceive('assertNotExceeded')->andThrow(new AiSpendCeilingExceededException(new HotelGroup, 12.0, 10.0));
    app()->instance(AiSpendCeiling::class, $ceiling);
    DocumentVisionAgent::fake()->preventStrayPrompts();

    $blocked = knVisionUpload($this, 'scanned.pdf');

    expect($blocked->failure_code->value)->toBe('usage_limit_reached');
});

it('reads the document in its own hotel context', function () {
    $seen = null;
    DocumentVisionAgent::fake(function () use (&$seen) {
        $seen = TenantContext::currentHotelId();

        return ['pages' => [['page' => 1, 'text' => 'SPA OPEN', 'description' => 'A sign.']]];
    });

    $document = knVisionUpload($this, 'sign.png');

    expect($seen)->toBe($document->hotel_id);
});
