<?php

use App\Enums\UserRole;
use App\Jobs\PruneReservationImportPreviewsJob;
use App\Models\ReservationImportPreview;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Column-mapping previews (016): upload once, see the columns with samples
 * and suggestions for any header row, list, reopen, discard, expire.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

/** A PMS-style report: three title lines, then the header on row 4. */
function pvReport(): UploadedFile
{
    return imXlsx([
        ['Grand Harbor Hotel'],
        ['Reservations report'],
        ['01/09/2025 - 30/09/2025'],
        ['Conf. No.', 'Guest Name', 'Mob.', 'Arr. Date', 'Dep. Date', 'Nationality'],
        ['GHH-1', 'Ahmed Saleh', '01001234567', new DateTime('2025-09-12'), '15/09/2025', 'EG'],
        ['GHH-2', 'Mona Fathy', '01001234568', new DateTime('2025-09-13'), '16/09/2025', 'EG'],
        ['GHH-3', 'John Smith', '+447911123456', new DateTime('2025-09-14'), '17/09/2025', 'GB'],
    ]);
}

function pvGet($test, User $user, string $uri): TestResponse
{
    return $test->withHeaders(apiHeaders())->actingAs($user, 'sanctum')->get($uri, ['Accept' => 'application/json']);
}

function pvPreview(string $id): ReservationImportPreview
{
    return TenantContext::withoutScope(fn () => ReservationImportPreview::findOrFail($id));
}

it('stores the upload and returns its columns, samples, first rows and data row count', function () {
    [$admin, $hotel] = imHotel();
    $file = imXlsx([
        ['Res No.', 'Mob.', 'Check-In', 'Departure'],
        ['R-1', '01001234567', new DateTime('2025-09-12'), '15/09/2025'],
        ['R-2', '', new DateTime('2025-09-13'), '16/09/2025'],
    ]);

    $response = imPreview($this, $admin, $file)->assertCreated();
    $body = $response->json('body');

    expect($body['header_row'])->toBe(1)
        ->and($body['data_rows'])->toBe(2)
        ->and($body['rows'])->toHaveCount(3)
        ->and($body['columns'])->toHaveCount(4)
        ->and($body['columns'][0])->toMatchArray(['position' => 0, 'letter' => 'A', 'header' => 'Res No.', 'samples' => ['R-1', 'R-2']])
        // An empty cell is not a sample.
        ->and($body['columns'][1]['samples'])->toBe(['01001234567'])
        // A date cell shows as a date, not as a spreadsheet serial number.
        ->and($body['columns'][2]['samples'][0])->toContain('2025')
        ->and($body['columns'][2]['samples'][0])->not->toBe('45912')
        ->and(collect($body['fields'])->firstWhere('key', 'arrival_date'))->toBe(['key' => 'arrival_date', 'label' => 'Arrival date', 'required' => true, 'guest_identifier' => false])
        ->and(collect($body['fields'])->firstWhere('key', 'guest_phone')['guest_identifier'])->toBeTrue()
        ->and(collect($body['fields'])->pluck('key'))->toContain('guest_full_name');

    $preview = pvPreview($body['id']);
    expect($preview->hotel_id)->toBe($hotel->id)
        ->and($preview->file_path)->toStartWith("reservation-import-previews/{$hotel->id}/");
    Storage::disk('local')->assertExists($preview->file_path);
});

it('rebuilds the columns from a chosen header row without a new upload', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, pvReport())->assertCreated()->json('body.id');

    $body = pvGet($this, $admin, "/api/reservation/import-previews/{$id}?header_row=4")->assertOk()->json('body');

    expect($body['header_row'])->toBe(4)
        ->and($body['data_rows'])->toBe(3)
        ->and(collect($body['columns'])->pluck('header')->all())->toBe(['Conf. No.', 'Guest Name', 'Mob.', 'Arr. Date', 'Dep. Date', 'Nationality'])
        ->and($body['columns'][0]['samples'])->toBe(['GHH-1', 'GHH-2', 'GHH-3'])
        ->and($body['rows'][0])->toBe(['Grand Harbor Hotel']);
});

it('refuses a header row outside the first 10 rows or past the end of the file', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, pvReport())->json('body.id');

    pvGet($this, $admin, "/api/reservation/import-previews/{$id}?header_row=11")->assertUnprocessable()->assertJsonValidationErrors('header_row');
    pvGet($this, $admin, "/api/reservation/import-previews/{$id}?header_row=8")->assertUnprocessable()
        ->assertJsonValidationErrors(['header_row' => 'The file has only 7 rows.']);
});

it('names a blank header after its column letter and keeps duplicate headers apart', function () {
    [$admin] = imHotel();
    $file = imXlsx([
        ['Phone', 'Phone', 'Arrival', 'Departure', '', 'Notes'],
        ['01001234567', '01001234568', '2025-09-12', '2025-09-14', 'x', ''],
    ]);

    $columns = imPreview($this, $admin, $file)->assertCreated()->json('body.columns');

    expect($columns[0]['header'])->toBe('Phone')
        ->and($columns[1]['header'])->toBe('Phone')
        ->and($columns[1]['samples'])->toBe(['01001234568'])
        ->and($columns[4]['header'])->toBe('Column E')
        ->and($columns[4]['samples'])->toBe(['x']);
});

it('refuses an unreadable or oversized file and stores nothing', function () {
    [$admin] = imHotel();

    // A zip signature with nothing a spreadsheet reader can open behind it.
    $corrupt = UploadedFile::fake()->createWithContent('reservations.xlsx', "PK\x03\x04".random_bytes(512));
    imPreview($this, $admin, $corrupt)->assertUnprocessable()->assertJsonValidationErrors('file');

    $huge = UploadedFile::fake()->create('reservations.xlsx', 6000);
    imPreview($this, $admin, $huge)->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(TenantContext::withoutScope(fn () => ReservationImportPreview::count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('lists unexpired previews newest first, without their columns', function () {
    [$admin] = imHotel();
    $first = imPreview($this, $admin, pvReport())->json('body.id');
    $this->travel(1)->minutes();
    $second = imPreview($this, $admin, pvReport())->json('body.id');
    $this->travel(1)->minutes();
    $expired = imPreview($this, $admin, pvReport())->json('body.id');
    pvPreview($expired)->update(['expires_at' => now()->subMinute()]);

    $items = pvGet($this, $admin, '/api/reservation/import-previews')->assertOk()->json('body.data');

    expect(collect($items)->pluck('id')->all())->toBe([$second, $first])
        ->and($items[0])->not->toHaveKeys(['rows', 'columns', 'fields'])
        ->and($items[0])->toHaveKeys(['file_name', 'uploaded_by', 'created_at', 'expires_at', 'imports']);
});

it('discards a preview and its file at once', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, pvReport())->json('body.id');
    $path = pvPreview($id)->file_path;

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/reservation/import-previews/{$id}")->assertOk();

    Storage::disk('local')->assertMissing($path);
    pvGet($this, $admin, "/api/reservation/import-previews/{$id}")->assertNotFound();
});

it('answers 410 for an expired preview and prunes it with its file', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, pvReport())->json('body.id');
    $preview = pvPreview($id);
    $preview->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

    pvGet($this, $admin, "/api/reservation/import-previews/{$id}")->assertStatus(410);
    imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => []])->assertStatus(410);

    (new PruneReservationImportPreviewsJob)->handle();

    Storage::disk('local')->assertMissing($preview->file_path);
    pvGet($this, $admin, "/api/reservation/import-previews/{$id}")->assertNotFound();
});

it('keeps an unexpired preview when pruning', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, pvReport())->json('body.id');

    (new PruneReservationImportPreviewsJob)->handle();

    pvGet($this, $admin, "/api/reservation/import-previews/{$id}")->assertOk();
});

it('makes a super admin name the hotel', function () {
    [, $hotel] = imHotel();
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    imPreview($this, $super, pvReport())->assertUnprocessable()->assertJsonValidationErrors('hotel_id');

    $id = imPreview($this, $super, pvReport(), ['hotel_id' => $hotel->id])->assertCreated()->json('body.id');
    expect(pvPreview($id)->hotel_id)->toBe($hotel->id);
});

// --- Suggestions (US2) -------------------------------------------------------

it('suggests the field a known header stands for and nothing for an unknown one', function () {
    [$admin] = imHotel();
    $file = imCsv(['Check-In', 'Departure', 'Email', 'Res No.', 'Arr Dt X', 'room_type'], [['2025-09-12', '2025-09-14', 'a@example.test', 'R-1', 'x', 'Deluxe']]);

    $columns = collect(imPreview($this, $admin, $file)->assertCreated()->json('body.columns'));

    expect($columns->pluck('suggested_field', 'header')->all())->toBe([
        'Check-In' => 'arrival_date',
        'Departure' => 'departure_date',
        'Email' => 'guest_email',
        'Res No.' => 'reservation_reference',
        'Arr Dt X' => null,
        // A header already named like a field is that field.
        'room_type' => 'room_type',
    ]);
});

it('suggests a field to only the first of two columns that claim it', function () {
    [$admin] = imHotel();
    $file = imCsv(['Mobile', 'Phone', 'Arrival', 'Departure'], [['01001234567', '01001234568', '2025-09-12', '2025-09-14']]);

    $columns = imPreview($this, $admin, $file)->assertCreated()->json('body.columns');

    expect([$columns[0]['suggested_field'], $columns[1]['suggested_field']])->toBe(['guest_phone', null]);
});
