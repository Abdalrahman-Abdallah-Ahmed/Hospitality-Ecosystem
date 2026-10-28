<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\User;
use App\Models\VoucherTemplate;
use App\Services\Vouchers\VoucherDataBuilder;
use App\Services\Vouchers\VoucherRenderer;
use App\Services\Vouchers\VoucherTemplateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake(config('vouchers.disk'));
});

function vtUpload($test, User $user, array $data, ?UploadedFile $image = null, string $uri = '/api/voucher-templates'): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key', 'Accept' => 'application/json'])
        ->actingAs($user, 'sanctum')
        ->post($uri, [...$data, 'image' => $image ?? vcImage()]);
}

function vtSend($test, User $user, string $method, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->json($method, $uri, $payload);
}

function vtCreate($test, Hotel $hotel, string $name = 'Standard EN', string $language = 'en', bool $default = false): VoucherTemplate
{
    $id = vtUpload($test, $hotel->owner, ['name' => $name, 'language' => $language, 'is_default' => $default ? '1' : '0'], vcImage(1240, 1754))
        ->assertCreated()
        ->json('body.id');

    return VoucherTemplate::withoutGlobalScopes()->findOrFail($id);
}

function vtBox(string $id, string $field = 'guest.primary_name', array $extra = []): array
{
    return ['id' => $id, 'field' => $field, 'x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.05, 'font_size' => 12, 'color' => '#1A1A1A', 'bold' => false, 'align' => 'start', 'overflow' => 'shrink', ...$extra];
}

// --- Upload (US2) -----------------------------------------------------------

it('stores an uploaded template with its original and a render copy', function (string $type, string $mime) {
    $hotel = avHotel();

    $response = vtUpload($this, $hotel->owner, ['name' => 'Standard', 'language' => 'en'], vcImage(2480, 3508, $type))
        ->assertCreated()
        ->assertJsonPath('body.layout', [])
        ->assertJsonPath('body.image.mime_type', $mime)
        ->assertJsonPath('body.image.width_px', 2480)
        ->assertJsonPath('body.image.height_px', 3508);

    $template = VoucherTemplate::findOrFail($response->json('body.id'));

    expect($template->hotel_id)->toBe($hotel->id)
        ->and($template->size_bytes)->toBeGreaterThan(0)
        ->and($template->render_path)->toEndWith('.jpg')
        ->and(Storage::disk(config('vouchers.disk'))->exists($template->original_path))->toBeTrue()
        ->and(Storage::disk(config('vouchers.disk'))->exists($template->render_path))->toBeTrue()
        ->and($response->json('body'))->not->toHaveKeys(['original_path', 'render_path', 'disk']);
})->with([
    'png' => ['png', 'image/png'],
    'jpg' => ['jpg', 'image/jpeg'],
    'webp' => ['webp', 'image/webp'],
]);

it('rejects an image that breaks a rule, naming the rule and storing nothing', function (Closure $image, string $key) {
    $hotel = avHotel();

    vtUpload($this, $hotel->owner, ['name' => 'Bad', 'language' => 'en'], $image())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($key);

    expect(VoucherTemplate::count())->toBe(0)
        ->and(Storage::disk(config('vouchers.disk'))->allFiles())->toBe([]);
})->with([
    'short side' => [fn () => vcImage(900, 1300), 'image.dimensions_short_side'],
    'long side' => [fn () => vcImage(2100, 6200), 'image.dimensions_long_side'],
    'ratio' => [fn () => vcImage(1000, 3500), 'image.ratio'],
    'too big' => [fn () => UploadedFile::fake()->create('big.png', 6000, 'image/png'), 'image'],
    'not an image' => [fn () => UploadedFile::fake()->createWithContent('fake.png', 'just some text'), 'image.type'],
    'gif' => [fn () => vcImage(1240, 1754, 'gif'), 'image'],
]);

it('keeps names unique per hotel, ignoring case', function () {
    $hotel = avHotel();
    $other = avHotel();
    vtCreate($this, $hotel, 'Standard');

    vtUpload($this, $hotel->owner, ['name' => 'STANDARD', 'language' => 'en'])->assertUnprocessable()->assertJsonValidationErrors('name');
    vtUpload($this, $other->owner, ['name' => 'Standard', 'language' => 'en'])->assertCreated();
});

it('flattens a transparent image onto white for printing', function () {
    $hotel = avHotel();

    $id = vtUpload($this, $hotel->owner, ['name' => 'Clear', 'language' => 'en'], vcImage(1240, 1754, 'png', transparent: true))->assertCreated()->json('body.id');
    $template = VoucherTemplate::findOrFail($id);

    $render = imagecreatefromstring(Storage::disk(config('vouchers.disk'))->get($template->render_path));
    $rgb = imagecolorsforindex($render, imagecolorat($render, 5, 5));

    expect([$rgb['red'], $rgb['green'], $rgb['blue']])->each->toBeGreaterThan(250);
});

// --- Layout, defaults, fields (US2) -----------------------------------------

it('saves a valid layout as sent, with defaults filled', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);

    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [
        vtBox('b1'),
        ['id' => 'b2', 'field' => 'free_text', 'text' => 'Welcome', 'x' => 0, 'y' => 0.9, 'w' => 1, 'h' => 0.1, 'font_size' => 10, 'color' => '#000000'],
    ]])->assertOk()
        ->assertJsonPath('body.layout.0.field', 'guest.primary_name')
        ->assertJsonPath('body.layout.1.text', 'Welcome')
        ->assertJsonPath('body.layout.1.align', 'start')
        ->assertJsonPath('body.layout.1.overflow', 'shrink');

    expect($template->fresh()->layout)->toHaveCount(2);
});

it('rejects an invalid layout as a whole, naming the box, and keeps the old one', function (array $layout, string $key) {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('keep')]])->assertOk();

    $response = vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => $layout])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($key);

    if (str_starts_with($key, 'layout.0.')) {
        expect(implode(' ', $response->json('errors')[$key]))->toContain('(box bad)');
    }

    expect($template->fresh()->layout[0]['id'])->toBe('keep');
})->with([
    'outside the image' => [[vtBox('bad', extra: ['x' => 0.8, 'w' => 0.5])], 'layout.0.w'],
    'zero height' => [[vtBox('bad', extra: ['h' => 0])], 'layout.0.h'],
    'unknown field' => [[vtBox('bad', 'guest.passport')], 'layout.0.field'],
    'named colour' => [[vtBox('bad', extra: ['color' => 'red'])], 'layout.0.color'],
    'font too big' => [[vtBox('bad', extra: ['font_size' => 80])], 'layout.0.font_size'],
    'left alignment' => [[vtBox('bad', extra: ['align' => 'left'])], 'layout.0.align'],
    'free text without text' => [[vtBox('bad', 'free_text')], 'layout.0.text'],
    'text on a data box' => [[vtBox('bad', extra: ['text' => 'x'])], 'layout.0.text'],
    'duplicate id' => [[vtBox('bad'), vtBox('bad')], 'layout.0.id'],
    'too many boxes' => [array_map(fn ($i) => vtBox("b{$i}"), range(1, 61)), 'layout'],
]);

it('keeps one default per language', function () {
    $hotel = avHotel();
    $first = vtCreate($this, $hotel, 'A', default: true);
    $second = vtCreate($this, $hotel, 'B');

    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$second->id}", ['is_default' => true])->assertOk();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();

    expect(fn () => VoucherTemplate::withoutGlobalScopes()->whereKey($first->id)->update(['is_default' => true]))
        ->toThrow(QueryException::class);
});

it('moves the default flag with a language change', function () {
    $hotel = avHotel();
    $english = vtCreate($this, $hotel, 'EN', 'en', true);
    $arabic = vtCreate($this, $hotel, 'AR', 'ar', true);

    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$english->id}", ['language' => 'ar'])->assertOk();

    expect($english->fresh()->is_default)->toBeTrue()
        ->and($english->fresh()->language->value)->toBe('ar')
        ->and($arabic->fresh()->is_default)->toBeFalse();
});

it('keeps the layout when the image is replaced', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('b1')]])->assertOk();
    $layout = $template->fresh()->layout;
    $oldRender = $template->fresh()->render_path;

    vtUpload($this, $hotel->owner, [], vcImage(2480, 3508, 'webp'), "/api/voucher-templates/{$template->id}/image")
        ->assertOk()
        ->assertJsonPath('body.image.width_px', 2480);

    expect($template->fresh()->layout)->toBe($layout)
        ->and(Storage::disk(config('vouchers.disk'))->exists($oldRender))->toBeFalse()
        ->and(Storage::disk(config('vouchers.disk'))->exists($template->fresh()->render_path))->toBeTrue();
});

it('lists every field with its kind and labels in both languages', function () {
    $hotel = avHotel();

    $fields = vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates/fields')->assertOk()->json('body');

    expect(array_column($fields, 'key'))->toBe(array_map(fn (VoucherField $f) => $f->value, VoucherField::cases()))
        ->and(collect($fields)->firstWhere('key', 'rooms.lines')['kind'])->toBe('list')
        ->and(collect($fields)->firstWhere('key', 'guest.primary_name')['label'])->toHaveKeys(['en', 'ar'])
        ->and(collect($fields)->firstWhere('key', 'voucher.staff_note')['label']['en'])->toBe('Staff note');

    foreach ($fields as $field) {
        foreach (['en', 'ar'] as $language) {
            expect($field['label'][$language])->not->toBe($field['key'])->not->toStartWith('voucher.fields');
        }
    }
});

// --- Preview (US3) ----------------------------------------------------------

it('previews with sample data, a reservation, or an unsaved layout, storing and sending nothing', function () {
    Mail::fake();
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('g', 'guest.primary_name', ['w' => 0.8, 'h' => 0.08])]])->assertOk();
    $reservation = vcReservation($hotel);
    $events = EventLog::count();

    $sample = vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(vcPdfText($sample->getContent()))->toContain('Alexandra');

    $real = vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", ['reservation_id' => $reservation->id])->assertOk();
    expect(vcPdfText($real->getContent()))->toContain('Layla Haddad');

    $unsaved = vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", [
        'layout' => [vtBox('n', 'free_text', ['text' => 'Unsaved layout text', 'w' => 0.8, 'h' => 0.08])],
    ])->assertOk();
    expect(vcPdfText($unsaved->getContent()))->toContain('Unsaved layout text')
        ->and($template->fresh()->layout[0]['id'])->toBe('g')
        ->and(EventLog::count())->toBe($events);

    Mail::assertNothingOutgoing();
});

it('checks preview date overrides against the previewed reservation\'s dates', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    $reservation = vcReservation($hotel); // 2026-12-10 → 2026-12-13

    vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", [
        'reservation_id' => $reservation->id,
        'overrides' => ['arrival_date' => '2026-12-11'],
    ])->assertOk();

    vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", [
        'reservation_id' => $reservation->id,
        'overrides' => ['departure_date' => '2026-12-09'],
    ])->assertUnprocessable()->assertJsonValidationErrors('overrides.departure_date');
});

it('renames a default template without touching its default flag in the audit trail', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel, 'Main', default: true);

    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}", ['name' => 'Renamed'])->assertOk();

    $updates = EventLog::where('subject_id', $template->id)->where('event_type', 'like', 'voucher_template.%')->where('event_type', '!=', 'voucher_template.created')->get();

    expect($updates)->toHaveCount(1)
        ->and(array_keys($updates->first()->changes))->toBe(['name'])
        ->and($template->fresh()->is_default)->toBeTrue();
});

it('still renders when the template image is replaced mid-render', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    $stale = VoucherTemplate::find($template->id);

    vtUpload($this, $hotel->owner, [], vcImage(2480, 3508), "/api/voucher-templates/{$template->id}/image")->assertOk();
    expect(Storage::disk(config('vouchers.disk'))->exists($stale->render_path))->toBeFalse();

    $pdf = app(VoucherRenderer::class)
        ->renderTemplate(app(VoucherDataBuilder::class)->sample(VoucherLanguage::EN), $stale)
        ->pdf;

    expect($pdf)->toStartWith('%PDF');
});

it('answers not found for a preview with another hotel\'s reservation', function () {
    $hotel = avHotel();
    $other = avHotel();
    $template = vtCreate($this, $hotel);

    vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", ['reservation_id' => vcReservation($other)->id])->assertNotFound();
});

it('needs the voucher permission too to preview with a real reservation', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    $reservation = vcReservation($hotel);
    $viewer = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_VIEW]);
    $both = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_VIEW, Permission::RESERVATIONS_VOUCHER]);

    vtSend($this, $viewer, 'POST', "/api/voucher-templates/{$template->id}/preview")->assertOk();
    vtSend($this, $viewer, 'POST', "/api/voucher-templates/{$template->id}/preview", ['reservation_id' => $reservation->id])->assertForbidden();
    vtSend($this, $both, 'POST', "/api/voucher-templates/{$template->id}/preview", ['reservation_id' => $reservation->id])->assertOk();
    vtSend($this, vcEmployee($hotel, [Permission::RESERVATIONS_VOUCHER]), 'POST', "/api/voucher-templates/{$template->id}/preview")->assertForbidden();
});

// --- Management (US6) -------------------------------------------------------

it('lists only the hotel\'s templates, with filters, search and sort', function () {
    $hotel = avHotel();
    $other = avHotel();
    vtCreate($this, $hotel, 'Alpha', 'en', true);
    vtCreate($this, $hotel, 'Beta', 'ar');
    vtCreate($this, $other, 'Gamma');

    expect(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates')->assertOk()->json('body.data'))->toHaveCount(2);
    expect(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates?filter[language]=ar')->json('body.data.0.name'))->toBe('Beta');
    expect(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates?filter[is_default]=1')->json('body.data.0.name'))->toBe('Alpha');
    expect(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates?search=Bet')->json('body.data'))->toHaveCount(1);
    expect(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates?sort=-name&per_page=1')->json('body.data.0.name'))->toBe('Beta');
});

it('shows, streams and deletes a template only within its hotel', function () {
    $hotel = avHotel();
    $other = avHotel();
    $template = vtCreate($this, $hotel, 'Main', 'en', true);
    $original = $template->original_path;

    vtSend($this, $hotel->owner, 'GET', "/api/voucher-templates/{$template->id}")->assertOk()->assertJsonPath('body.name', 'Main');
    vtSend($this, $other->owner, 'GET', "/api/voucher-templates/{$template->id}")->assertNotFound();
    vtSend($this, $other->owner, 'PUT', "/api/voucher-templates/{$template->id}", ['name' => 'X'])->assertNotFound();
    vtSend($this, $other->owner, 'DELETE', "/api/voucher-templates/{$template->id}")->assertNotFound();

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->get("/api/voucher-templates/{$template->id}/image")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $this->withHeaders(['X-API-KEY' => 'test-api-key', 'Accept' => 'application/json'])->actingAs($other->owner, 'sanctum')
        ->get("/api/voucher-templates/{$template->id}/image")
        ->assertNotFound();

    vtSend($this, $hotel->owner, 'DELETE', "/api/voucher-templates/{$template->id}")->assertOk();

    expect(VoucherTemplate::withTrashed()->find($template->id)->trashed())->toBeTrue()
        ->and(Storage::disk(config('vouchers.disk'))->exists($original))->toBeTrue()
        ->and(vtSend($this, $hotel->owner, 'GET', '/api/voucher-templates')->json('body.data'))->toBe([]);

    // The name is free again, and vouchers fall back to the built-in layout.
    vtCreate($this, $hotel, 'Main');
    $reservation = vcReservation($hotel);
    vcPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/voucher")->assertOk();
    expect(EventLog::where('event_type', 'reservation.voucher_generated')->sole()->context['voucher_template_id'])->toBeNull();
});

it('keeps one hotel out of another hotel voucher templates (SPEC-013)', function () {
    $hotel = avHotel();
    $other = avHotel();
    $template = vtCreate($this, $hotel, 'Main');
    $before = $template->fresh()->toArray();
    $intruder = $other->owner;

    vtSend($this, $intruder, 'GET', "/api/voucher-templates/{$template->id}")->assertNotFound();
    vtSend($this, $intruder, 'PUT', "/api/voucher-templates/{$template->id}", ['name' => 'Taken'])->assertNotFound();
    vtSend($this, $intruder, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('x')]])->assertNotFound();
    vtSend($this, $intruder, 'POST', "/api/voucher-templates/{$template->id}/preview")->assertNotFound();
    vtSend($this, $intruder, 'DELETE', "/api/voucher-templates/{$template->id}")->assertNotFound();
    vtUpload($this, $intruder, [], vcImage(1240, 1754), "/api/voucher-templates/{$template->id}/image")->assertNotFound();
    $this->withHeaders(['X-API-KEY' => 'test-api-key', 'Accept' => 'application/json'])->actingAs($intruder, 'sanctum')
        ->get("/api/voucher-templates/{$template->id}/image")->assertNotFound();

    // A spoofed hotel_id on create is ignored: the template lands in the caller's hotel.
    $id = vtUpload($this, $intruder, ['name' => 'Spoof', 'language' => 'en', 'hotel_id' => $hotel->id])->assertCreated()->json('body.id');

    expect(VoucherTemplate::withoutGlobalScopes()->find($id)->hotel_id)->toBe($other->id)
        ->and($template->fresh()->toArray())->toBe($before);
});

it('gates each template action by its own permission', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    $viewer = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_VIEW]);
    $creator = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_CREATE]);
    $updater = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_UPDATE]);
    $deleter = vcEmployee($hotel, [Permission::VOUCHER_TEMPLATES_DELETE]);
    $noRole = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);

    vtSend($this, $viewer, 'GET', '/api/voucher-templates')->assertOk();
    vtSend($this, $creator, 'GET', '/api/voucher-templates')->assertForbidden();

    vtUpload($this, $creator, ['name' => 'By creator', 'language' => 'en'])->assertCreated();
    vtUpload($this, $viewer, ['name' => 'By viewer', 'language' => 'en'])->assertForbidden();

    vtSend($this, $updater, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('b')]])->assertOk();
    vtSend($this, $viewer, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('b')]])->assertForbidden();

    vtSend($this, $viewer, 'DELETE', "/api/voucher-templates/{$template->id}")->assertForbidden();
    vtSend($this, $deleter, 'DELETE', "/api/voucher-templates/{$template->id}")->assertOk();

    vtSend($this, $noRole, 'GET', '/api/voucher-templates')->assertForbidden();
});

it('creates a super admin\'s template in the hotel they name', function () {
    $hotel = avHotel();
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    vtUpload($this, $super, ['name' => 'HQ', 'language' => 'en'])->assertForbidden();

    $id = vtUpload($this, $super, ['name' => 'HQ', 'language' => 'en', 'hotel_id' => $hotel->id])->assertCreated()->json('body.id');

    expect(VoucherTemplate::withoutGlobalScopes()->find($id)->hotel_id)->toBe($hotel->id);
});

it('lets a super admin list one hotel\'s templates by hotel_id', function () {
    $hotel = avHotel();
    $other = avHotel();
    vtCreate($this, $hotel, 'Alpha');
    vtCreate($this, $other, 'Gamma');
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    expect(vtSend($this, $super, 'GET', '/api/voucher-templates')->assertOk()->json('body.data'))->toHaveCount(2);
    expect(vtSend($this, $super, 'GET', "/api/voucher-templates?filter[hotel_id]={$hotel->id}")->assertOk()->json('body.data.*.name'))->toBe(['Alpha']);
});

// --- Review fixes -----------------------------------------------------------

it('stamps previews and refuses to preview a reservation that cannot get a voucher', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);

    $pdf = vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview")->assertOk()->getContent();
    expect(vcPdfText($pdf))->toContain('PREVIEW');

    foreach (['cancelled', 'no_show'] as $status) {
        vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", [
            'reservation_id' => vcReservation($hotel, ['status' => $status])->id,
        ])->assertStatus(409);
    }
});

it('applies overrides to a sample-data preview', function () {
    $hotel = avHotel();
    $template = vtCreate($this, $hotel);
    vtSend($this, $hotel->owner, 'PUT', "/api/voucher-templates/{$template->id}/layout", ['layout' => [vtBox('g', 'guest.primary_name', ['w' => 0.8, 'h' => 0.08])]])->assertOk();

    $pdf = vtSend($this, $hotel->owner, 'POST', "/api/voucher-templates/{$template->id}/preview", [
        'overrides' => ['primary_guest_name' => 'Override Name', 'departure_date' => '2020-01-01'],
    ])->assertOk()->getContent();

    expect(vcPdfText($pdf))->toContain('Override Name');
});

it('answers a name taken by a concurrent request with a validation error', function () {
    $hotel = avHotel();
    $service = app(VoucherTemplateService::class);
    $service->create($hotel, ['name' => 'Same', 'language' => 'en'], vcImage(1240, 1754), null);

    // The second request passed validation before the first one committed.
    expect(fn () => $service->create($hotel, ['name' => 'same', 'language' => 'ar'], vcImage(1240, 1754), null))
        ->toThrow(ValidationException::class);
    expect(VoucherTemplate::count())->toBe(1);
});
