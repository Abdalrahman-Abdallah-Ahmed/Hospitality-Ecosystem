<?php

use App\Enums\Permission;
use App\Models\KnowledgeBaseArticle;
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

function knAdminUpload($test, $user, string $fixture, array $fields = [])
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->post('/api/admin/knowledge-documents', ['file' => knUpload($fixture), 'title' => 'Standard practice', ...$fields], ['Accept' => 'application/json']);
}

it('lets a super admin run the whole life of a global document from the admin area', function () {
    $super = knSuperAdmin();
    [, $hotel] = knHotel();

    $id = knAdminUpload($this, $super, 'text.pdf', ['hotel_id' => $hotel->id])
        ->assertCreated()
        ->assertJsonPath('body.hotel_id', null)
        ->assertJsonPath('body.status', 'uploaded')
        ->json('body.id');

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->find($id))
        ->path->toStartWith("knowledge/global/{$id}/")
        ->status->value->toBe('indexed');

    $call = fn (string $method, string $uri, array $payload = []) => knRequest($this, $super, $method, "/api/admin/knowledge-documents{$uri}", $payload);

    $call('GET', '')->assertOk()->assertJsonCount(1, 'body.data');
    $call('GET', "/{$id}")->assertOk()->assertJsonPath('body.title', 'Standard practice');
    $call('PUT', "/{$id}", ['title' => 'Standard check-out practice'])->assertOk();
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->get("/api/admin/knowledge-documents/{$id}/download")->assertOk();
    $text = $call('GET', "/{$id}/text")->assertOk()->json('body.segments');
    $text[0]['text'] = 'Corrected welcome text for every hotel.';
    $call('PUT', "/{$id}/text", ['segments' => $text])->assertStatus(202);
    $call('DELETE', "/{$id}/text")->assertStatus(202);
    $call('POST', "/{$id}/reindex")->assertStatus(202);
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->post("/api/admin/knowledge-documents/{$id}/file", ['file' => knUpload('notes.md')], ['Accept' => 'application/json'])
        ->assertStatus(202);
    $call('DELETE', "/{$id}")->assertOk();
    expect($call('GET', '/deleted')->assertOk()->json('body.data.0.id'))->toBe($id);
    $call('POST', "/{$id}/restore")->assertOk();

    expect(KnowledgeDocument::withoutGlobalScope('hotel')->find($id))
        ->hotel_id->toBeNull()
        ->title->toBe('Standard check-out practice')
        ->original_filename->toBe('notes.md');
});

it('makes an indexed global document part of every hotel search', function () {
    $super = knSuperAdmin();
    [, $hotelA] = knHotel();
    [, $hotelB] = knHotel();

    knAdminUpload($this, $super, 'plain.txt')->assertCreated();

    expect(json_decode(knSearch($hotelA), true)[0])->scope->toBe('general')->content->toContain('Reception is open 24 hours');
    expect(knSearch($hotelB))->toContain('Reception is open 24 hours');
});

it('never lists or serves a hotel document from the admin area', function () {
    $super = knSuperAdmin();
    [, $hotel] = knHotel();
    $hotelDocument = knDocument($hotel, ['title' => 'Hotel only']);
    knDocument(null, ['title' => 'Global']);

    expect(collect(knRequest($this, $super, 'GET', '/api/admin/knowledge-documents')->json('body.data'))->pluck('title')->all())
        ->toBe(['Global']);
    knRequest($this, $super, 'GET', "/api/admin/knowledge-documents/{$hotelDocument->id}")->assertNotFound();
    knRequest($this, $super, 'PUT', "/api/admin/knowledge-documents/{$hotelDocument->id}", ['title' => 'x'])->assertNotFound();
    knRequest($this, $super, 'DELETE', "/api/admin/knowledge-documents/{$hotelDocument->id}")->assertNotFound();
});

it('checks duplicates among global documents only', function () {
    $super = knSuperAdmin();
    [$admin] = knHotel();

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Hotel copy'], ['Accept' => 'application/json'])
        ->assertCreated();

    knAdminUpload($this, $super, 'plain.txt')->assertCreated();
    knAdminUpload($this, $super, 'plain.txt')->assertStatus(409);
});

it('manages global articles in the admin area, including ones created before it existed', function () {
    $super = knSuperAdmin();
    [, $hotel] = knHotel();
    $legacy = KnowledgeBaseArticle::create(['hotel_id' => null, 'title' => 'Legacy global', 'content' => 'Greet guests warmly.', 'status' => 'published']);
    KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Hotel article', 'content' => 'Hotel only.', 'status' => 'published']);

    $call = fn (string $method, string $uri, array $payload = []) => knRequest($this, $super, $method, "/api/admin/knowledge-base-articles{$uri}", $payload);

    expect(collect($call('GET', '')->assertOk()->json('body.data'))->pluck('title')->all())->toBe(['Legacy global']);

    $id = $call('POST', '', ['title' => 'New global', 'content' => 'Offer water on arrival.', 'status' => 'published', 'hotel_id' => $hotel->id])
        ->assertCreated()
        ->assertJsonPath('body.hotel_id', null)
        ->json('body.id');

    $call('PUT', "/{$id}", ['title' => 'New global, edited'])->assertOk()->assertJsonPath('body.hotel_id', null);
    $call('GET', "/{$legacy->id}")->assertOk();
    $call('DELETE', "/{$legacy->id}")->assertOk();

    $hotelArticle = KnowledgeBaseArticle::withoutGlobalScope('hotel')->where('title', 'Hotel article')->sole();
    $call('GET', "/{$hotelArticle->id}")->assertNotFound();
    $call('PUT', "/{$hotelArticle->id}", ['title' => 'x'])->assertNotFound();

    expect(knSearch($hotel))->toContain('Offer water on arrival.');
});

it('refuses hotel admins and employees on every admin knowledge route, whatever their role grants', function () {
    [$admin, $hotel] = knHotel();
    $employee = knEmployee($hotel, array_filter(Permission::cases(), fn (Permission $p) => str_starts_with($p->value, 'knowledge')));
    $global = knDocument(null);

    foreach ([$admin, $employee] as $user) {
        knRequest($this, $user, 'GET', '/api/admin/knowledge-documents')->assertForbidden();
        knRequest($this, $user, 'POST', '/api/admin/knowledge-documents', ['title' => 'x'])->assertForbidden();
        knRequest($this, $user, 'PUT', "/api/admin/knowledge-documents/{$global->id}", ['title' => 'x'])->assertForbidden();
        knRequest($this, $user, 'DELETE', "/api/admin/knowledge-documents/{$global->id}")->assertForbidden();
        knRequest($this, $user, 'GET', '/api/admin/knowledge-base-articles')->assertForbidden();
        knRequest($this, $user, 'POST', '/api/admin/knowledge-base-articles', ['title' => 'x', 'content' => 'y'])->assertForbidden();
    }

    expect($global->fresh()->title)->toBe('House Rules');
});

it('keeps global documents out of reach on the hotel routes, download included', function () {
    [$admin, $hotel] = knHotel();
    $global = knDocument(null);
    Storage::disk('local')->put($global->path, 'global file');

    knRequest($this, $admin, 'GET', "/api/knowledge-documents/{$global->id}")->assertNotFound();
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->get("/api/knowledge-documents/{$global->id}/download", ['Accept' => 'application/json'])->assertNotFound();
    knRequest($this, $admin, 'DELETE', "/api/knowledge-documents/{$global->id}")->assertNotFound();
});
