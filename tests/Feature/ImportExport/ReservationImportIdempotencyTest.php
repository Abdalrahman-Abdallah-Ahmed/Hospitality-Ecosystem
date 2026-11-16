<?php

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStatus;
use App\Jobs\PrepareReservationImportJob;
use App\Jobs\WriteReservationImportJob;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationImport;
use App\Models\ReservationRoom;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\Import\Reservations\ReservationImportWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| SPEC-100/101 User Story 3: re-importing is safe (FR-021–FR-024, SC-002,
| SC-007). The external reference is the idempotency key.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

const ID_HEADER = ['reservation_reference', 'guest_first_name', 'guest_phone', 'room_type', 'arrival_date', 'departure_date', 'status', 'activity_name', 'activity_date'];

function idRows(): array
{
    return [
        ['R-1', 'Ann', '01001110001', 'Deluxe', '2026-11-01', '2026-11-04', 'confirmed', 'Sunset cruise', '2026-11-02'],
        ['R-2', 'Bob', '01001110002', 'Suite', '2026-11-02', '2026-11-05', 'confirmed', '', ''],
        ['R-3', 'Cy', '01001110003', 'Deluxe', '2026-11-03', '2026-11-06', 'pending', '', ''],
        ['', 'Di', '01001110004', 'Deluxe', '2026-11-03', '2026-11-06', 'pending', '', ''],
    ];
}

function idCounts(): array
{
    return [Reservation::withoutGlobalScope('hotel')->count(), Guest::withoutGlobalScope('hotel')->count(), Booking::withoutGlobalScope('hotel')->count(), ReservationRoom::withoutGlobalScope('hotel')->count()];
}

it('reports every reservation of a re-imported file as a duplicate and creates nothing', function () {
    [$admin, $hotel] = imHotel();
    abActivity($hotel);

    $first = imImport($this, $admin, imCsv(ID_HEADER, idRows()));
    $before = idCounts();
    $second = imImport($this, $admin, imCsv(ID_HEADER, idRows()));

    expect($first->imported)->toBe(4)
        ->and($before)->toBe([4, 4, 1, 4])
        ->and($second->duplicate)->toBe(4)
        ->and($second->imported)->toBe(0)
        ->and(idCounts())->toBe($before)
        ->and(imRows($second)->pluck('reservation_id')->filter()->count())->toBe(4)
        ->and(imRows($second)[2]->reservation_id)->toBe(Reservation::where('reservation_id', 'R-1')->value('id'));
});

it('imports rows corrected since an earlier run, and reports the rest as duplicates', function () {
    [$admin] = imHotel();
    $rows = idRows();
    $rows[1][4] = 'not a date';

    $first = imImport($this, $admin, imCsv(ID_HEADER, $rows));
    $second = imImport($this, $admin, imCsv(ID_HEADER, idRows()));

    expect($first->invalid)->toBe(1)
        ->and($second->imported)->toBe(1)
        ->and($second->duplicate)->toBe(3)
        ->and(imRows($second)[3]->outcome)->toBe(ImportRowOutcome::IMPORTED);
});

it('treats a reference-less copy of a reservation as a duplicate, in the file and at the hotel', function () {
    [$admin] = imHotel();
    $row = ['', 'Di', '01001110004', 'Deluxe', '2026-11-03', '2026-11-06', 'pending', '', ''];

    $first = imImport($this, $admin, imCsv(ID_HEADER, [$row, $row]));
    $second = imImport($this, $admin, imCsv(ID_HEADER, [$row]));

    expect($first->imported)->toBe(1)
        ->and(imRows($first)[3]->outcome)->toBe(ImportRowOutcome::DUPLICATE)
        ->and(imRows($first)[3]->reason)->toContain('row 2')
        ->and($second->duplicate)->toBe(1)
        ->and(Reservation::count())->toBe(1)
        ->and(Reservation::sole()->reservation_id)->toStartWith('RES-');
});

it('treats references as unique per hotel', function () {
    [$adminA] = imHotel();
    [$adminB, $hotelB] = imHotel();

    imImport($this, $adminA, imCsv(ID_HEADER, idRows()));
    $import = imImport($this, $adminB, imCsv(ID_HEADER, idRows()));

    expect($import->imported)->toBe(4)
        ->and(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $hotelB->id)->count())->toBe(4);
});

it('leaves a stored reservation unchanged when the file differs, saying so', function () {
    [$admin] = imHotel();
    imImport($this, $admin, imCsv(ID_HEADER, idRows()));

    $rows = idRows();
    $rows[0][5] = '2026-11-09';
    $rows[0][6] = 'cancelled';
    $import = imImport($this, $admin, imCsv(ID_HEADER, $rows));

    $warning = imRows($import)[2]->warnings[0];
    expect(imRows($import)[2]->outcome)->toBe(ImportRowOutcome::DUPLICATE)
        ->and($warning['code'])->toBe('data_differs')
        ->and($warning['message'])->toContain('departure_date')->toContain('status')
        ->and(Reservation::where('reservation_id', 'R-1')->value('departure_date')->toDateString())->toBe('2026-11-04');
});

it('restores a deleted reservation the file names again', function () {
    [$admin] = imHotel();
    imImport($this, $admin, imCsv(ID_HEADER, [idRows()[1]]));
    Reservation::where('reservation_id', 'R-2')->sole()->delete();

    $import = imImport($this, $admin, imCsv(ID_HEADER, [idRows()[1]]));

    expect(imRows($import)[2]->outcome)->toBe(ImportRowOutcome::IMPORTED)
        ->and(imRows($import)[2]->warnings[0]['code'])->toBe('restored')
        ->and(Reservation::where('reservation_id', 'R-2')->exists())->toBeTrue()
        ->and(Reservation::withTrashed()->count())->toBe(1);
});

it('runs a hotel imports one at a time, in upload order', function () {
    Queue::fake();
    [$admin, $hotel] = imHotel();
    [$otherAdmin] = imHotel();

    $first = imUpload($this, $admin, imCsv(ID_HEADER, idRows()))->json('body.id');
    $this->travel(1)->seconds();
    $second = imUpload($this, $admin, imCsv(ID_HEADER, idRows()))->json('body.id');
    $other = imUpload($this, $otherAdmin, imCsv(ID_HEADER, idRows()))->json('body.id');

    // The newer upload waits while the first is queued or running.
    app()->call([new PrepareReservationImportJob($second), 'handle']);
    expect(ReservationImport::find($second)->status)->toBe(ImportStatus::QUEUED);

    // Another hotel never waits.
    expect(imRun($other)->status)->toBe(ImportStatus::COMPLETED);

    app()->call([new PrepareReservationImportJob($first), 'handle']);
    app()->call([new PrepareReservationImportJob($second), 'handle']);
    expect(ReservationImport::find($first)->status)->toBe(ImportStatus::RUNNING)
        ->and(ReservationImport::find($second)->status)->toBe(ImportStatus::QUEUED);

    expect(imRun($first)->imported)->toBe(4)
        ->and(imRun($second)->duplicate)->toBe(4)
        ->and(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(4);
});

it('ends a crash and a re-run with the same data as one clean run (SC-007)', function () {
    Queue::fake();
    config(['reservation_import.write_chunk_size' => 2]);
    [$admin, $hotel] = imHotel();
    abActivity($hotel);

    $id = imUpload($this, $admin, imCsv(ID_HEADER, idRows()))->json('body.id');
    app()->call([new PrepareReservationImportJob($id), 'handle']);
    app()->call([new WriteReservationImportJob($id), 'handle']);

    // The worker dies before the next chunk; the job gives up.
    (new WriteReservationImportJob($id))->failed(new RuntimeException('worker lost'));
    $crashed = ReservationImport::find($id);

    $rerun = imImport($this, $admin, imCsv(ID_HEADER, idRows()));

    expect($crashed->status)->toBe(ImportStatus::FAILED)
        ->and($crashed->failure_reason)->toContain('Upload the file again')
        ->and([$crashed->imported, $crashed->invalid, $crashed->processed_rows])->toBe([2, 2, 4])
        ->and(imRows($crashed)[4]->reason)->toContain('Upload the file again to import it')
        ->and($rerun->imported)->toBe(2)
        ->and($rerun->duplicate)->toBe(2)
        ->and(idCounts())->toBe([4, 4, 1, 4]);
});

it('reports a reservation that fails while writing as invalid, without internals, and carries on', function () {
    [$admin] = imHotel();
    app()->bind(ReservationImportWriter::class, fn ($app) => new class($app->make(BookingService::class), $app->make(AvailabilityService::class)) extends ReservationImportWriter
    {
        public function write(array $draft, Hotel $hotel): array
        {
            if ($draft['reference'] === 'R-2') {
                throw new RuntimeException('SQLSTATE[XX000]: boom');
            }

            return parent::write($draft, $hotel);
        }
    });

    $import = imImport($this, $admin, imCsv(ID_HEADER, idRows()));

    expect($import->imported)->toBe(3)
        ->and($import->invalid)->toBe(1)
        ->and(imRows($import)[3]->stage->value)->toBe('import')
        ->and(imRows($import)[3]->reason)->toStartWith('Could not be saved')->not->toContain('SQLSTATE')
        ->and(Reservation::where('reservation_id', 'R-2')->exists())->toBeFalse();
});

it('matches a reference the hotel already has whatever its case', function () {
    [$admin, $hotel] = imHotel();
    createReservationWithRooms($hotel, [[]], ['reservation_id' => 'RES-001']);

    $import = imImport($this, $admin, imCsv(ID_HEADER, [['res-001', 'Ann', '01001110001', 'Deluxe', '2026-11-01', '2026-11-04', 'confirmed', '', '']]));

    expect($import->duplicate)->toBe(1)
        ->and(Reservation::whereRaw('lower(reservation_id) = ?', ['res-001'])->count())->toBe(1);
});

it('keeps upload order for two uploads in the same second', function () {
    Queue::fake();
    $this->freezeTime();
    [$admin] = imHotel();

    $ids = collect(range(1, 5))->map(fn () => imUpload($this, $admin, imCsv(ID_HEADER, idRows()))->json('body.id'));

    foreach ($ids->slice(1) as $later) {
        app()->call([new PrepareReservationImportJob($later), 'handle']);
        expect(ReservationImport::find($later)->status)->toBe(ImportStatus::QUEUED);
    }

    app()->call([new PrepareReservationImportJob($ids->first()), 'handle']);
    expect(ReservationImport::find($ids->first())->status)->toBe(ImportStatus::RUNNING);
});
