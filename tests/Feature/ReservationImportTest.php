<?php

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStatus;
use App\Enums\UserRole;
use App\Jobs\PrepareReservationImportJob;
use App\Jobs\WriteReservationImportJob;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationImport;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Import\Reservations\ImportReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/*
| SPEC-100/101 User Story 1: an upload is queued at once and ends with a
| reconciliation report (FR-001–FR-005, FR-025–FR-029, FR-031, FR-032).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

const IM_HEADER = ['guest_phone', 'guest_first_name', 'guest_last_name', 'room_number', 'arrival_date', 'departure_date'];

function imGet($test, User $user, string $uri)
{
    return $test->withHeaders(apiHeaders())->actingAs($user, 'sanctum')->getJson($uri);
}

// --- The queued contract ---------------------------------------------------

it('rejects an unauthenticated import request', function () {
    $this->withHeaders(apiHeaders())->postJson('/api/reservation/import')->assertStatus(401);
});

it('rejects an employee without the import permission', function () {
    [, $hotel] = adminWithHotel();
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);

    imUpload($this, $worker, imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]))->assertStatus(403);
});

it('queues the import and answers at once with the import', function () {
    Queue::fake();
    [$admin, $hotel] = adminWithHotel();

    $response = imUpload($this, $admin, imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']], 'september.csv'))
        ->assertStatus(202)
        ->assertJsonPath('body.status', 'queued')
        ->assertJsonPath('body.file_name', 'september.csv')
        ->assertJsonPath('body.date_order', 'day_first')
        ->assertJsonPath('body.dry_run', false)
        ->assertJsonPath('body.uploaded_by.id', $admin->id)
        ->assertJsonPath('body.progress.processed_rows', 0)
        ->assertJsonPath('body.totals.imported', 0)
        ->assertJsonPath('body.warnings_count', 0)
        ->assertJsonStructure(['body' => ['id', 'progress' => ['processed_rows', 'total_rows'], 'totals' => ['imported', 'skipped', 'duplicate', 'invalid', 'unresolved'], 'created_rooms', 'created_room_types', 'unrecognized_columns', 'warnings_count', 'failure_reason', 'created_at', 'started_at', 'finished_at']]);

    Queue::assertPushed(PrepareReservationImportJob::class, fn ($job) => $job->importId === $response->json('body.id'));
    expect(Reservation::where('hotel_id', $hotel->id)->exists())->toBeFalse();
});

it('validates the date order and the file size', function () {
    [$admin] = adminWithHotel();

    imUpload($this, $admin, imCsv(IM_HEADER, []), ['date_order' => 'sideways'])
        ->assertUnprocessable()->assertJsonValidationErrors('date_order');

    imUpload($this, $admin, UploadedFile::fake()->create('huge.csv', 6000, 'text/csv'))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('takes a decimal mark on a direct upload, defaulting to auto (016)', function () {
    [$admin] = imHotel();
    $file = fn () => imCsv([...IM_HEADER, 'reservation_value'], [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04', '25,200']]);

    $auto = imImport($this, $admin, $file());
    expect($auto->decimal_mark->value)->toBe('auto')
        ->and($auto->invalid)->toBe(1);

    [$other] = imHotel();
    $dot = imImport($this, $other, $file(), ['decimal_mark' => 'dot']);
    expect($dot->decimal_mark->value)->toBe('dot')
        ->and($dot->imported)->toBe(1)
        ->and($dot->column_map)->toBeNull()
        ->and($dot->header_row)->toBe(1);

    imUpload($this, $admin, $file(), ['decimal_mark' => 'euro'])->assertUnprocessable()->assertJsonValidationErrors('decimal_mark');
});

it('makes a super admin name the hotel', function () {
    [, $hotel] = adminWithHotel();
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $file = fn () => imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]);

    imUpload($this, $super, $file())->assertUnprocessable()->assertJsonValidationErrors('hotel_id');

    $import = imRun(imUpload($this, $super, $file(), ['hotel_id' => $hotel->id])->assertStatus(202)->json('body.id'));

    expect($import->hotel_id)->toBe($hotel->id)
        ->and($import->imported)->toBe(1);
});

// --- Running and reporting -------------------------------------------------

it('accounts for every data row, with a reason for each one not imported', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $import = imImport($this, $admin, imCsv(IM_HEADER, [
        ['555-0100', 'Ann', 'Lee', '101', '2026-09-01', '2026-09-04'],
        ['555-0200', 'Bob', 'Ray', '', '2026-09-02', '2026-09-05'],
        ['555-0300', 'No', 'Arrival', '', '', '2026-09-06'],
        ['555-0400', 'Bad', 'Date', '', '2026-13-45', '2026-09-06'],
        ['', '', '', '', '', ''],
        ['555-0500', 'Back', 'Wards', '', '2026-09-06', '2026-09-03'],
    ]));

    $rows = imRows($import);

    expect($import->status)->toBe(ImportStatus::COMPLETED)
        ->and($import->total_rows)->toBe(6)
        ->and($import->imported + $import->skipped + $import->duplicate + $import->invalid + $import->unresolved)->toBe(6)
        ->and([$import->imported, $import->invalid, $import->skipped])->toBe([2, 3, 1])
        ->and($import->processed_rows)->toBe(6)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->file_path)->toBeNull()
        ->and($rows[4]->outcome)->toBe(ImportRowOutcome::INVALID)
        ->and($rows[4]->fields)->toBe(['arrival_date'])
        ->and($rows[5]->fields)->toBe(['arrival_date'])
        ->and($rows[5]->reason)->toContain('2026-13-45')
        ->and($rows[6]->outcome)->toBe(ImportRowOutcome::SKIPPED)
        ->and($rows[7]->reason)->toContain('must be after the arrival');

    foreach ($rows->whereNotIn('outcome', [ImportRowOutcome::IMPORTED]) as $row) {
        expect($row->reason)->not->toBeEmpty()->not->toMatch('/[A-Z][a-z]+\\\\[A-Z]|Exception|SQLSTATE/');
    }

    expect(Reservation::where('hotel_id', $hotel->id)->count())->toBe(2)
        ->and(ReservationRoom::where('room_id', $room->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('fails a file missing a required column, naming it, and writes nothing', function () {
    [$admin, $hotel] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(['guest_phone', 'departure_date'], [['555-0100', '2026-09-04']]));

    expect($import->status)->toBe(ImportStatus::FAILED)
        ->and($import->failure_reason)->toContain('arrival_date')
        ->and(Reservation::where('hotel_id', $hotel->id)->exists())->toBeFalse();
});

it('completes an empty file with nothing to do', function () {
    [$admin] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(IM_HEADER, []));

    expect($import->status)->toBe(ImportStatus::COMPLETED)
        ->and($import->total_rows)->toBe(0);
});

it('does not count blank rows after the data', function () {
    [$admin] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(IM_HEADER, [
        ['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04'],
        ...array_fill(0, 50, ['', '', '', '', '', '']),
    ]));

    expect($import->total_rows)->toBe(1)
        ->and($import->imported)->toBe(1);
});

it('reports progress chunk by chunk', function () {
    config(['reservation_import.write_chunk_size' => 2]);
    Queue::fake();
    [$admin] = adminWithHotel();

    $id = imUpload($this, $admin, imCsv(IM_HEADER, collect(range(1, 5))->map(fn ($i) => ["555-010{$i}", 'Guest', "{$i}", '', '2026-09-01', '2026-09-04'])->all()))->json('body.id');

    app()->call([new PrepareReservationImportJob($id), 'handle']);

    $runChunk = fn () => app()->call([new WriteReservationImportJob($id), 'handle']);
    $progress = fn () => ReservationImport::find($id)->processed_rows;

    expect($progress())->toBe(0);
    $runChunk();
    expect($progress())->toBe(2);
    $runChunk();
    expect($progress())->toBe(4);
    $runChunk();
    expect($progress())->toBe(5)
        ->and(ReservationImport::find($id)->status)->toBe(ImportStatus::COMPLETED);
});

it('lists the hotel imports newest first, and filters rows by outcome', function () {
    [$admin] = adminWithHotel();
    $first = imImport($this, $admin, imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']], 'first.csv'));
    $this->travel(1)->minutes();
    $second = imImport($this, $admin, imCsv(IM_HEADER, [
        ['555-0200', 'Bob', 'Ray', '', '2026-09-01', '2026-09-04'],
        ['555-0300', 'No', 'Date', '', '', '2026-09-04'],
    ], 'second.csv'));

    imGet($this, $admin, '/api/reservation/imports')
        ->assertOk()
        ->assertJsonPath('body.data.0.id', $second->id)
        ->assertJsonPath('body.data.0.file_name', 'second.csv')
        ->assertJsonPath('body.data.0.uploaded_by.name', $admin->name)
        ->assertJsonPath('body.data.0.totals.invalid', 1)
        ->assertJsonPath('body.data.1.id', $first->id);

    imGet($this, $admin, "/api/reservation/imports/{$second->id}")
        ->assertOk()->assertJsonPath('body.status', 'completed')->assertJsonPath('body.progress.total_rows', 2);

    imGet($this, $admin, "/api/reservation/imports/{$second->id}/rows?outcome=invalid")
        ->assertOk()
        ->assertJsonCount(1, 'body.data')
        ->assertJsonPath('body.data.0.row_number', 3)
        ->assertJsonPath('body.data.0.fields', ['arrival_date']);
});

it('downloads the report of a finished import, and refuses while it runs', function () {
    [$admin, $hotel] = adminWithHotel();
    $import = imImport($this, $admin, imCsv(IM_HEADER, [['555-0300', 'No', 'Date', '', '', '2026-09-04']]));
    Excel::fake();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->get("/api/reservation/imports/{$import->id}/report")
        ->assertOk();
    Excel::assertDownloaded("import-{$import->id}-report.xlsx", fn ($export) => $export->query()->count() === 1);

    $running = ReservationImport::create(['hotel_id' => $hotel->id, 'file_name' => 'x.csv', 'status' => ImportStatus::RUNNING, 'date_order' => 'day_first']);
    imGet($this, $admin, "/api/reservation/imports/{$running->id}/report")->assertStatus(409);
});

it('records one audit event per run with the uploader as actor, and none per reservation', function () {
    [$admin, $hotel] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(IM_HEADER, [
        ['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04'],
        ['555-0200', 'Bob', 'Ray', '', '2026-09-01', '2026-09-04'],
    ]));

    $event = EventLog::where('event_type', 'hotel.reservations_imported')->sole();

    expect($event->actor_id)->toBe($admin->id)
        ->and($event->changes['reservation_import_id'])->toBe($import->id)
        ->and($event->changes['imported'])->toBe(2)
        ->and(EventLog::where('event_type', 'reservation.created')->exists())->toBeFalse();
});

it('finishes the run and records no actor when the uploader is deleted mid-run', function () {
    Queue::fake();
    [$admin] = adminWithHotel();
    $id = imUpload($this, $admin, imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]))->json('body.id');

    $admin->forceDelete();
    auth()->forgetGuards();
    $import = imRun($id);

    expect($import->status)->toBe(ImportStatus::COMPLETED)
        ->and($import->uploaded_by)->toBeNull()
        ->and(EventLog::where('event_type', 'hotel.reservations_imported')->sole()->actor_id)->toBeNull();
});

// --- Behaviour kept from the first import ----------------------------------

it('creates a room the file names but the hotel does not have, under the default type', function () {
    [$admin, $hotel] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(IM_HEADER, [['555-0300', 'New', 'Room', '204', '2026-09-01', '2026-09-04']]));

    $room = Room::where('hotel_id', $hotel->id)->where('room_number', '204')->sole();
    expect($import->created_rooms)->toBe(['204'])
        ->and($import->created_room_types)->toBe([RoomType::DEFAULT_NAME])
        ->and($room->roomType->name)->toBe(RoomType::DEFAULT_NAME)
        ->and(ReservationRoom::where('room_id', $room->id)->exists())->toBeTrue();
});

it('recognizes an existing guest whatever the phone formatting', function () {
    [$admin, $hotel] = adminWithHotel();
    $existing = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 115 179 3758']);

    imImport($this, $admin, imCsv(IM_HEADER, [['201151793758', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]));

    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and(Reservation::where('hotel_id', $hotel->id)->sole()->guest_id)->toBe($existing->id);
});

it('files a type-only row as one unassigned line of that type', function () {
    [$admin, $hotel] = adminWithHotel();

    imImport($this, $admin, imCsv(['guest_phone', 'arrival_date', 'departure_date', 'room_type'], [['555-0100', '2026-09-01', '2026-09-04', 'Suite']]));

    $line = ReservationRoom::sole();
    expect($line->room_id)->toBeNull()
        ->and($line->roomType->name)->toBe('Suite')
        ->and($line->hotel_id)->toBe($hotel->id);
});

it('records legacy parties and deactivated room types as they are (FR-010)', function () {
    [$admin, $hotel] = adminWithHotel();
    $retired = RoomType::resolveFor($hotel->id, 'Retired');
    $retired->update(['is_active' => false]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $retired->id, 'room_number' => '301']);

    imImport($this, $admin, imCsv(['guest_phone', 'arrival_date', 'departure_date', 'adults', 'room_number'], [['555-0100', '2026-09-01', '2026-09-04', 9, '301']]));

    expect(Reservation::sole()->adults)->toBe(9)
        ->and(ReservationRoom::sole()->room_type_id)->toBe($retired->id);
});

it('imports a named party with child ages through the shared reservation rules', function () {
    [$admin] = adminWithHotel();

    imImport($this, $admin, imCsv(['guest_phone', 'guest_first_name', 'arrival_date', 'departure_date', 'adults', 'children', 'children_ages', 'guests'], [[
        '555-0201', 'Primary', '2026-11-01', '2026-11-04', 1, 2, '5,8',
        json_encode([['phone_number' => '555-0201', 'is_primary' => true], ['phone_number' => '555-0202', 'first_name' => 'Additional']]),
    ]]));

    $reservation = Reservation::sole();
    expect($reservation->children_ages)->toBe([5, 8])
        ->and($reservation->reservationGuests()->count())->toBe(2)
        ->and($reservation->reservationGuests()->where('is_primary', true)->sole()->guest_id)->toBe($reservation->guest_id);
});

it('rejects a guest list naming another hotel guest, writing nothing', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $other] = adminWithHotel();
    $foreign = Guest::create(['hotel_id' => $other->id, 'phone_number' => '555-0205']);

    $import = imImport($this, $admin, imCsv(['guest_phone', 'arrival_date', 'departure_date', 'adults', 'guests'], [[
        '555-0204', '2026-11-01', '2026-11-04', 2, json_encode([['guest_id' => $foreign->id]]),
    ]]));

    expect($import->invalid)->toBe(1)
        ->and(Guest::where('hotel_id', $hotel->id)->exists())->toBeFalse()
        ->and(Reservation::where('hotel_id', $hotel->id)->exists())->toBeFalse();
});

it('writes nothing for a reservation that fails, leaving no stray guest or room', function () {
    [$admin, $hotel] = adminWithHotel();

    imImport($this, $admin, imCsv(['guest_phone', 'arrival_date', 'departure_date', 'room_number', 'status'], [['555-0199', '2026-09-01', '2026-09-04', '777', 'typo']]));

    expect(Guest::where('hotel_id', $hotel->id)->exists())->toBeFalse()
        ->and(Room::where('hotel_id', $hotel->id)->where('room_number', '777')->exists())->toBeFalse();
});

it('keeps the import within the hotel it was started for', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $other] = adminWithHotel();

    $import = imImport($this, $admin, imCsv(IM_HEADER, [['555-0100', 'Ann', 'Lee', '101', '2026-09-01', '2026-09-04']]));

    expect(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $other->id)->exists())->toBeFalse()
        ->and(Room::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and($import->hotel_id)->toBe($hotel->id)
        ->and(Hotel::count())->toBe(2);
});

it('lists the rows a failed run never wrote in the report', function () {
    Queue::fake();
    config(['reservation_import.write_chunk_size' => 1]);
    [$admin] = adminWithHotel();
    $id = imUpload($this, $admin, imCsv(IM_HEADER, [
        ['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04'],
        ['555-0200', 'Bob', 'Ray', '', '2026-09-01', '2026-09-04'],
    ]))->json('body.id');

    app()->call([new PrepareReservationImportJob($id), 'handle']);
    app()->call([new WriteReservationImportJob($id), 'handle']);
    (new WriteReservationImportJob($id))->failed(new RuntimeException('worker lost'));
    $import = ReservationImport::find($id);

    expect($import->imported + $import->invalid)->toBe($import->total_rows)
        ->and((new ImportReportExport($import))->query()->pluck('row_number')->all())->toBe([3]);
});
