<?php

use App\Enums\Permission;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    Queue::fake();
    knFakeEmbeddings();
});

function knUploadAs($test, $user, string $fixture, array $fields = [], ?string $as = null)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload($fixture, $as), 'title' => 'House Rules 2026', ...$fields], ['Accept' => 'application/json']);
}

it('stores an upload as uploaded and queues indexing without waiting for it', function () {
    [$admin, $hotel] = knHotel();

    $response = knUploadAs($this, $admin, 'text.pdf', ['category' => 'hospitality_best_practices']);

    $response->assertCreated()
        ->assertJsonPath('body.status', 'uploaded')
        ->assertJsonPath('body.title', 'House Rules 2026')
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.mime_type', 'application/pdf')
        ->assertJsonPath('body.original_filename', 'text.pdf')
        ->assertJsonMissingPath('body.path')
        ->assertJsonMissingPath('body.disk')
        ->assertJsonMissingPath('body.content_hash');

    $document = KnowledgeDocument::withoutGlobalScope('hotel')->sole();
    expect($document->path)->toStartWith("knowledge/{$hotel->id}/{$document->id}/");
    Storage::disk('local')->assertExists($document->path);
    expect(hash('sha256', Storage::disk('local')->get($document->path)))->toBe(hash_file('sha256', knFixturePath('text.pdf')));

    Queue::assertPushed(IndexKnowledgeDocumentJob::class, fn ($job) => $job->documentId === $document->id && $job->mode === 'extract');
});

it('rejects a file whose content does not match its extension, and stores nothing', function () {
    [$admin] = knHotel();

    knUploadAs($this, $admin, 'fake.pdf')
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'The file type does not match its extension.');

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects unsupported types and files over the size limit', function () {
    [$admin] = knHotel();

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->postJson('/api/knowledge-documents', ['file' => UploadedFile::fake()->create('tool.exe', 10), 'title' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->postJson('/api/knowledge-documents', ['file' => UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf'), 'title' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('requires a title', function () {
    [$admin] = knHotel();

    knUploadAs($this, $admin, 'plain.txt', ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
});

it('rejects the same file twice in one hotel and points to the existing document, but allows it at another hotel', function () {
    [$admin] = knHotel();
    [$otherAdmin] = knHotel();

    $first = knUploadAs($this, $admin, 'text.pdf')->assertCreated();

    knUploadAs($this, $admin, 'text.pdf', ['title' => 'Again'])
        ->assertStatus(409)
        ->assertJsonPath('body.existing.id', $first->json('body.id'))
        ->assertJsonPath('body.existing.title', 'House Rules 2026');

    knUploadAs($this, $otherAdmin, 'text.pdf')->assertCreated();
});

it('lists the hotel documents with filters and a title search', function () {
    [$admin, $hotel] = knHotel();

    knDocument($hotel, ['title' => 'Pool Rules', 'category' => 'hospitality_best_practices']);
    knDocument($hotel, ['title' => 'Spa Menu', 'is_active' => false]);
    knDocument($hotel, ['title' => 'Broken scan', 'status' => 'failed', 'failure_code' => 'no_text']);

    $titles = fn (string $query) => collect(knRequest($this, $admin, 'GET', '/api/knowledge-documents?'.$query)->assertOk()->json('body.data'))->pluck('title')->sort()->values()->all();

    expect($titles(''))->toBe(['Broken scan', 'Pool Rules', 'Spa Menu']);
    expect($titles('filter[status]=failed'))->toBe(['Broken scan']);
    expect($titles('filter[is_active]=0'))->toBe(['Spa Menu']);
    expect($titles('filter[category]=hospitality_best_practices'))->toBe(['Pool Rules']);
    expect($titles('search=Spa'))->toBe(['Spa Menu']);
});

it('shows the plain-language failure reason', function () {
    [$admin, $hotel] = knHotel();
    $document = knDocument($hotel, ['status' => 'failed', 'failure_code' => 'encrypted']);

    knRequest($this, $admin, 'GET', "/api/knowledge-documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('body.failure_code', 'encrypted')
        ->assertJsonPath('body.failure_message', 'The file is password-protected. Remove the password and upload it again.');
});

it('streams the original file to permitted staff only', function () {
    [$admin, $hotel] = knHotel();

    $id = knUploadAs($this, $admin, 'plain.txt')->json('body.id');

    $download = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->get("/api/knowledge-documents/{$id}/download");

    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('plain.txt');
    expect($download->streamedContent())->toBe(file_get_contents(knFixturePath('plain.txt')));

    knRequest($this, knEmployee($hotel, []), 'GET', "/api/knowledge-documents/{$id}/download")->assertForbidden();
});

it('makes a super admin name the hotel, and files the document under it', function () {
    [, $hotel] = knHotel();
    $super = knSuperAdmin();

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes'], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $id = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes', 'hotel_id' => $hotel->id], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->json('body.id');

    knRequest($this, $super, 'GET', "/api/knowledge-documents/{$id}?hotel_id={$hotel->id}")->assertOk();
    knRequest($this, $super, 'PUT', "/api/knowledge-documents/{$id}?hotel_id={$hotel->id}", ['title' => 'Renamed'])->assertOk();
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->get("/api/knowledge-documents/{$id}/download?hotel_id={$hotel->id}")->assertOk();
});

it('never shows or serves a global document on the hotel routes', function () {
    [$admin, $hotel] = knHotel();
    $global = knDocument(null, ['title' => 'Platform guidance']);

    expect(collect(knRequest($this, $admin, 'GET', '/api/knowledge-documents')->json('body.data'))->pluck('id'))
        ->not->toContain($global->id);

    knRequest($this, $admin, 'GET', "/api/knowledge-documents/{$global->id}")->assertNotFound();
    knRequest($this, $admin, 'GET', "/api/knowledge-documents/{$global->id}/download")->assertNotFound();
    knRequest($this, $admin, 'PUT', "/api/knowledge-documents/{$global->id}", ['title' => 'Hijacked'])->assertNotFound();
    knRequest($this, knSuperAdmin(), 'GET', "/api/knowledge-documents/{$global->id}?hotel_id={$hotel->id}")->assertNotFound();
});

it('lets an employee upload only with the create permission', function () {
    [, $hotel] = knHotel();

    knUploadAs($this, knEmployee($hotel, []), 'plain.txt')->assertForbidden();
    knUploadAs($this, knEmployee($hotel, [Permission::KNOWLEDGE_DOCUMENTS_CREATE]), 'plain.txt')->assertCreated();
});
