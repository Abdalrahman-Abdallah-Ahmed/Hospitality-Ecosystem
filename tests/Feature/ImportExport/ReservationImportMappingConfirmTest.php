<?php

use App\Enums\ImportStatus;
use App\Models\Reservation;
use App\Models\ReservationImport;
use App\Models\ReservationImportMapping;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/*
 * Confirming a column-mapping preview (016): the mapping drives a normal
 * queued import; a bad mapping is refused whole, creating nothing.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

/** Headers no synonym knows, so only a mapping can read them. */
const MC_HEADER = ['Ankunft', 'Abreise', 'Handy', 'Nationality'];

const MC_ROWS = [
    ['2026-09-01', '2026-09-04', '01001110001', 'EG'],
    ['2026-09-02', '2026-09-05', '01001110002', 'DE'],
    ['2026-09-03', '2026-09-03', '01001110003', 'EG'], // departure not after arrival
];

const MC_MAP = ['0' => 'arrival_date', '1' => 'departure_date', '2' => 'guest_phone', '3' => null];

function mcImports(): Collection
{
    return TenantContext::withoutScope(fn () => ReservationImport::orderBy('created_at')->get());
}

function mcMapping(string $hotelId): ?ReservationImportMapping
{
    return TenantContext::withoutScope(fn () => ReservationImportMapping::where('hotel_id', $hotelId)->first());
}

it('imports a file with unknown headers through the confirmed mapping, like a direct upload of the same rows', function () {
    [$admin, $hotel] = imHotel();
    $previewId = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->assertCreated()->json('body.id');

    $body = imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => MC_MAP])->assertStatus(202)->json('body');

    expect($body)->toMatchArray([
        'preview_id' => $previewId,
        'header_row' => 1,
        'column_map' => MC_MAP,
        'decimal_mark' => 'auto',
        'date_order' => 'day_first',
        'dry_run' => false,
    ]);

    $mapped = imRun($body['id']);

    // The same rows under canonical headers, uploaded directly to another hotel.
    [$other] = imHotel();
    $direct = imImport($this, $other, imCsv(['arrival_date', 'departure_date', 'guest_phone'], array_map(fn ($r) => array_slice($r, 0, 3), MC_ROWS)));

    expect($mapped->status)->toBe(ImportStatus::COMPLETED)
        ->and($mapped->hotel_id)->toBe($hotel->id)
        ->and([$mapped->imported, $mapped->invalid])->toBe([2, 1])
        ->and([$mapped->imported, $mapped->invalid])->toBe([$direct->imported, $direct->invalid])
        ->and($mapped->unrecognized_columns)->toBe(['Nationality'])
        ->and(imRows($mapped)->map->outcome->values()->all())->toEqual(imRows($direct)->map->outcome->values()->all());
});

it('numbers rows from the file when the header is below title lines', function () {
    [$admin] = imHotel();
    $file = imXlsx([
        ['Grand Harbor Hotel'],
        ['Reservations report'],
        ['Report date: 30/09/2025'],
        MC_HEADER,
        ...MC_ROWS,
    ]);
    $previewId = imPreview($this, $admin, $file)->json('body.id');

    $import = imRun(imConfirm($this, $admin, $previewId, ['header_row' => 4, 'columns' => MC_MAP])->assertStatus(202)->json('body.id'));

    expect($import->total_rows)->toBe(3)
        ->and(imRows($import)->keys()->all())->toBe([5, 6, 7])
        ->and(imRows($import)[7]->outcome->value)->toBe('invalid');
});

it('refuses a mapping that breaks a rule, creating nothing', function (array $columns, string $message) {
    [$admin, $hotel] = imHotel();
    $previewId = imPreview($this, $admin, imCsv([...MC_HEADER, 'Vorname', 'Ganzer Name'], [[...MC_ROWS[0], 'Ahmed', 'Ahmed Saleh']]))->json('body.id');

    imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => $columns])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['columns' => $message]);

    expect(mcImports())->toBeEmpty()
        ->and(mcMapping($hotel->id))->toBeNull();
})->with([
    'no arrival date' => [['1' => 'departure_date', '2' => 'guest_phone'], 'Map a column to Arrival date.'],
    'no departure date' => [['0' => 'arrival_date', '2' => 'guest_phone'], 'Map a column to Departure date.'],
    'no guest identifier' => [['0' => 'arrival_date', '1' => 'departure_date'], 'Map a column to at least one of Guest phone, Guest email, Guest id (old system).'],
    'a field twice' => [[...MC_MAP, '3' => 'guest_phone'], 'Guest phone is mapped to more than one column.'],
    'a column the file lacks' => [[...MC_MAP, '99' => 'status'], 'The file has no column at position 99.'],
    'an unknown field' => [[...MC_MAP, '3' => 'fax'], '"fax" is not a field this import reads.'],
    'full name with first name' => [[...MC_MAP, '4' => 'guest_first_name', '5' => 'guest_full_name'], 'Map either Guest full name or Guest first name / Guest last name, not both.'],
]);

it('reports every mapping problem in one response', function () {
    [$admin] = imHotel();
    $previewId = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');

    $errors = imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => ['2' => 'guest_phone', '3' => 'guest_phone']])
        ->assertUnprocessable()->json('errors.columns');

    expect($errors)->toContain('Map a column to Arrival date.')
        ->and($errors)->toContain('Map a column to Departure date.')
        ->and($errors)->toContain('Guest phone is mapped to more than one column.');
});

it('refuses a header row past the end of the file', function () {
    [$admin] = imHotel();
    $previewId = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');

    imConfirm($this, $admin, $previewId, ['header_row' => 9, 'columns' => MC_MAP])
        ->assertUnprocessable()->assertJsonValidationErrors('header_row');

    expect(mcImports())->toBeEmpty();
});

it('lets one preview be confirmed as a dry run and then for real', function () {
    [$admin] = imHotel();
    $previewId = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');

    $dry = imRun(imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => MC_MAP, 'dry_run' => true])->assertStatus(202)->json('body.id'));
    $real = imRun(imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => MC_MAP])->assertStatus(202)->json('body.id'));

    expect($dry->dry_run)->toBeTrue()
        ->and($real->dry_run)->toBeFalse()
        ->and([$dry->imported, $real->imported])->toBe([2, 2]);

    $imports = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->get("/api/reservation/import-previews/{$previewId}", ['Accept' => 'application/json'])
        ->assertOk()->json('body.imports');

    expect(collect($imports)->pluck('id')->all())->toBe([$real->id, $dry->id]);
});

it('keeps the imports readable after their preview is discarded', function () {
    [$admin] = imHotel();
    $previewId = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');
    $import = imRun(imConfirm($this, $admin, $previewId, ['header_row' => 1, 'columns' => MC_MAP])->json('body.id'));

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->deleteJson("/api/reservation/import-previews/{$previewId}")->assertOk();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->get("/api/reservation/imports/{$import->id}", ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('body.preview_id', null)
        ->assertJsonPath('body.totals.imported', 2);
});

it('gives the same result as a direct upload when the suggestions are confirmed unchanged (SC-002)', function () {
    $header = ['Res No.', 'Mobile', 'Check-In', 'Check-Out', 'Status'];
    $rows = [
        ['R-1', '01001110001', '2026-09-01', '2026-09-04', 'Confirmed'],
        ['R-2', '01001110002', '2026-09-02', '2026-09-05', 'Tentative'],
        ['R-3', '01001110003', '2026-09-03', '2026-09-01', 'Confirmed'],
    ];

    [$admin] = imHotel();
    $preview = imPreview($this, $admin, imCsv($header, $rows))->assertCreated()->json('body');
    $columns = collect($preview['columns'])->mapWithKeys(fn ($c) => [(string) $c['position'] => $c['suggested_field']])->all();
    $mapped = imRun(imConfirm($this, $admin, $preview['id'], ['header_row' => 1, 'columns' => $columns])->assertStatus(202)->json('body.id'));

    [$other] = imHotel();
    $direct = imImport($this, $other, imCsv($header, $rows));

    expect(imRows($mapped)->map(fn ($r) => [$r->outcome->value, $r->external_reference, $r->reason])->values()->all())
        ->toEqual(imRows($direct)->map(fn ($r) => [$r->outcome->value, $r->external_reference, $r->reason])->values()->all())
        ->and([$mapped->imported, $mapped->invalid])->toBe([2, 1])
        ->and($mapped->unrecognized_columns)->toBe($direct->unrecognized_columns);
});

// --- The hotel's mapping is remembered (US3) ---------------------------------

/** Mob. and Email in a file whose header is on row 2. */
function mcTitledFile(array $header): UploadedFile
{
    $values = ['Mob.' => '01001110001', 'Email' => 'a@example.test', 'Ankunft' => '2026-09-01', 'Abreise' => '2026-09-04', 'Status' => 'Confirmed'];

    return imXlsx([['Reservations report'], $header, array_map(fn ($h) => $values[$h], $header)]);
}

it('suggests the hotel\'s last mapping, header row and deliberate gaps included, for its next file', function () {
    [$admin, $hotel] = imHotel();
    $first = imPreview($this, $admin, mcTitledFile(['Ankunft', 'Abreise', 'Mob.', 'Email']))->json('body.id');
    imConfirm($this, $admin, $first, ['header_row' => 2, 'columns' => ['0' => 'arrival_date', '1' => 'departure_date', '2' => 'guest_phone', '3' => null]])->assertStatus(202);

    // Same headers in another order, plus one the mapping never saw.
    $next = imPreview($this, $admin, mcTitledFile(['Email', 'Status', 'Mob.', 'Abreise', 'Ankunft']))->assertCreated()->json('body');

    expect($next['suggested_header_row'])->toBe(2)
        ->and($next['header_row'])->toBe(2)
        ->and(collect($next['columns'])->pluck('suggested_field', 'header')->all())->toBe([
            // Left unmapped on purpose last time, although "Email" is a known name.
            'Email' => null,
            // Not in the saved mapping: the synonym table still applies.
            'Status' => 'status',
            'Mob.' => 'guest_phone',
            'Abreise' => 'departure_date',
            'Ankunft' => 'arrival_date',
        ])
        ->and(mcMapping($hotel->id)->updated_by)->toBe($admin->id);
});

it('saves the mapping on a dry run too', function () {
    [$admin, $hotel] = imHotel();
    $id = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');

    imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => MC_MAP, 'dry_run' => true])->assertStatus(202);

    expect(mcMapping($hotel->id)->columns)->toEqual(['ankunft' => 'arrival_date', 'abreise' => 'departure_date', 'handy' => 'guest_phone', 'nationality' => null])
        ->and(mcMapping($hotel->id)->header_row)->toBe(1);
});

it('leaves the saved mapping alone when a confirm is refused (SC-006)', function () {
    [$admin, $hotel] = imHotel();
    $id = imPreview($this, $admin, imCsv(MC_HEADER, MC_ROWS))->json('body.id');
    imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => MC_MAP])->assertStatus(202);

    imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => ['3' => 'guest_phone']])->assertUnprocessable();

    expect(mcMapping($hotel->id)->columns['nationality'])->toBeNull()
        ->and(mcMapping($hotel->id)->columns['handy'])->toBe('guest_phone');
});

it('falls back to the first row when the file is shorter than the saved header row', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, mcTitledFile(['Ankunft', 'Abreise', 'Mob.']))->json('body.id');
    imConfirm($this, $admin, $id, ['header_row' => 2, 'columns' => ['0' => 'arrival_date', '1' => 'departure_date', '2' => 'guest_phone']])->assertStatus(202);

    $short = imPreview($this, $admin, imCsv(['Ankunft'], []))->assertCreated()->json('body');

    expect($short['suggested_header_row'])->toBe(1);
});

// --- Decimal mark (US5) ------------------------------------------------------

it('stores the chosen decimal mark and reads amounts by it', function () {
    [$admin] = imHotel();
    $id = imPreview($this, $admin, imCsv([...MC_HEADER, 'Total'], [[...MC_ROWS[0], 'EGP 25,200'], [...MC_ROWS[1], '1,260.50']]))->json('body.id');
    $map = [...MC_MAP, '4' => 'reservation_value'];

    imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => $map, 'decimal_mark' => 'euro'])
        ->assertUnprocessable()->assertJsonValidationErrors('decimal_mark');

    $body = imConfirm($this, $admin, $id, ['header_row' => 1, 'columns' => $map, 'decimal_mark' => 'dot'])->assertStatus(202)->json('body');
    $import = imRun($body['id']);

    expect($body['decimal_mark'])->toBe('dot')
        ->and($import->imported)->toBe(2)
        ->and(Reservation::withoutGlobalScope('hotel')->orderBy('arrival_date')->pluck('reservation_value')->map(fn ($v) => (float) $v)->all())->toBe([25200.0, 1260.5]);
});
