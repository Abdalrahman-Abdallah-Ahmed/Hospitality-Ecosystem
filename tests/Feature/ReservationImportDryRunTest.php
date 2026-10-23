<?php

use App\Enums\ImportStatus;
use App\Jobs\PrepareReservationImportJob;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationImport;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| SPEC-100/101 User Story 5: a dry run goes through every stage except
| writing and reports what a real import would do (FR-030, US5-1, US5-2).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

const DR_HEADER = ['reservation_reference', 'guest_phone', 'guest_email', 'room_type', 'room_number', 'arrival_date', 'departure_date', 'status', 'activity_name', 'activity_date'];

function drFile(): array
{
    return [
        ['DR-1', '01001110001', '', 'Garden', '901', '2026-11-01', '2026-11-04', 'confirmed', 'Sunset cruise', '2026-11-02'],
        ['DR-2', '01001110002', '', 'Garden', '901', '2026-11-02', '2026-11-05', 'confirmed', 'Camel ride', '2026-11-03'],
        ['DR-3', '01001234567', 'mona@example.com', 'Deluxe', '', '2026-11-01', '2026-11-04', 'confirmed', '', ''],
        ['EXIST-1', '01001110004', '', 'Deluxe', '', '2026-11-01', '2026-11-04', 'confirmed', '', ''],
        ['DR-5', '01001110005', '', 'Deluxe', '', 'nope', '2026-11-04', 'confirmed', '', ''],
        ['DR-6', '', 'mail@example.com', '', '', '2026-11-01', '2026-11-04', '', '', ''],
    ];
}

function drCounts(): array
{
    return collect([Reservation::class, Guest::class, Room::class, RoomType::class, Booking::class, Stay::class])
        ->mapWithKeys(fn (string $model) => [class_basename($model) => $model::withoutGlobalScope('hotel')->count()])
        ->all();
}

function drOutcomes(ReservationImport $import): array
{
    return imRows($import)->map(fn ($row) => [$row->outcome->value, collect($row->warnings)->pluck('code')->sort()->values()->all()])->all();
}

it('writes nothing and reports what a real import of the same file then does', function () {
    [$admin, $hotel] = imHotel();
    abActivity($hotel);
    Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Ahmed', 'phone_number' => '201001234567']);
    Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Mona', 'email' => 'mona@example.com']);
    createReservationWithRooms($hotel, [[]], ['reservation_id' => 'EXIST-1']);
    $before = drCounts();

    $dry = imImport($this, $admin, imCsv(DR_HEADER, drFile()), ['dry_run' => 1]);

    expect($dry->status)->toBe(ImportStatus::COMPLETED)
        ->and($dry->dry_run)->toBeTrue()
        ->and(drCounts())->toBe($before)
        ->and($dry->created_rooms)->toBe(['901'])
        ->and($dry->created_room_types)->toBe(['Garden'])
        ->and([$dry->imported, $dry->duplicate, $dry->invalid, $dry->unresolved])->toBe([3, 1, 1, 1])
        ->and(imRows($dry)->pluck('reservation_id')->filter()->count())->toBe(1);

    $real = imImport($this, $admin, imCsv(DR_HEADER, drFile()));

    expect(drOutcomes($real))->toBe(drOutcomes($dry))
        ->and([$real->imported, $real->duplicate, $real->invalid, $real->unresolved])->toBe([3, 1, 1, 1]);
});

it('waits behind a real import of the same hotel', function () {
    Queue::fake();
    [$admin] = imHotel();

    $real = imUpload($this, $admin, imCsv(DR_HEADER, drFile()))->json('body.id');
    $this->travel(1)->seconds();
    $dry = imUpload($this, $admin, imCsv(DR_HEADER, drFile()), ['dry_run' => 1])->json('body.id');

    app()->call([new PrepareReservationImportJob($dry), 'handle']);
    expect(ReservationImport::find($dry)->status)->toBe(ImportStatus::QUEUED);

    imRun($real);
    expect(imRun($dry)->status)->toBe(ImportStatus::COMPLETED);
});
