<?php

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ImportRowOutcome;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| SPEC-100/101 User Story 2: legacy rows become the reservation model —
| grouped reservations, room units, guests, party, statuses against dates,
| room clashes and bookings (FR-011–FR-017c).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
    // 2026-10-23 in Cairo all day.
    Carbon::setTestNow('2026-10-23 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

const MP_HEADER = ['reservation_reference', 'guest_first_name', 'guest_phone', 'guest_email', 'is_primary', 'room_type', 'room_number', 'arrival_date', 'departure_date', 'status', 'adults', 'children', 'children_ages'];

/**
 * One import of rows given as column => value maps under MP_HEADER plus any
 * extra columns they name.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function mpImport($test, array $rows, array $fields = [])
{
    [$admin, $hotel] = $test->hotel ?? ($test->hotel = imHotel('Africa/Cairo'));
    $header = array_values(array_unique([...MP_HEADER, ...collect($rows)->flatMap(fn ($row) => array_keys($row))->all()]));
    $values = array_map(fn (array $row) => array_map(fn (string $column) => $row[$column] ?? '', $header), $rows);

    return imImport($test, $admin, imCsv($header, $values), $fields);
}

function mpHotel($test)
{
    return ($test->hotel ??= imHotel('Africa/Cairo'))[1];
}

function mpReservations($test)
{
    return Reservation::withoutGlobalScope('hotel')->where('hotel_id', mpHotel($test)->id)->with('reservationRooms.room', 'reservationRooms.roomType', 'reservationGuests.guest')->get();
}

// --- Grouping, rooms and guests (scenarios 1, 2, 5, 6, 10) ---------------------

it('groups the rows of one reference into one reservation with a line per room', function () {
    $hotel = mpHotel($this);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::resolveFor($hotel->id, 'Dbl Deluxe')->id, 'room_number' => '214']);

    $import = mpImport($this, [
        ['reservation_reference' => 'BK-1', 'guest_first_name' => 'Ahmed', 'guest_phone' => '+20 100 123 4567', 'room_type' => 'Dbl Deluxe', 'room_number' => '214', 'arrival_date' => '12/11/2026', 'departure_date' => '15/11/2026', 'status' => 'Confirmed', 'adults' => 2],
        ['reservation_reference' => 'bk-1', 'guest_first_name' => 'Mona', 'guest_phone' => '01001234568', 'is_primary' => 'yes', 'room_type' => 'Dbl Deluxe', 'room_number' => '215'],
        ['reservation_reference' => 'BK-1', 'room_type' => 'Suite', 'arrival_date' => '2026-11-12'],
    ]);

    $reservation = mpReservations($this)->sole();
    $lines = $reservation->reservationRooms->sortBy(fn ($line) => $line->room?->room_number ?? 'zzz')->values();

    expect($import->imported)->toBe(3)
        ->and($reservation->reservation_id)->toBe('BK-1')
        ->and($reservation->arrival_date->toDateString())->toBe('2026-11-12')
        ->and($reservation->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($lines)->toHaveCount(3)
        ->and($lines[0]->room->room_number)->toBe('214')
        ->and($lines[1]->room->room_number)->toBe('215')
        ->and($lines[2]->room_id)->toBeNull()
        ->and($lines[2]->roomType->name)->toBe('Suite')
        ->and($reservation->reservationGuests)->toHaveCount(2)
        ->and($reservation->guest->first_name)->toBe('Mona')
        ->and($reservation->guest->phone_number)->toBe('201001234568')
        ->and($import->created_rooms)->toBe(['215'])
        ->and($import->created_room_types)->toBe(['Suite']);
});

it('makes the first named guest primary when none is flagged, and refuses two primaries', function () {
    mpImport($this, [
        ['reservation_reference' => 'P-1', 'guest_first_name' => 'First', 'guest_phone' => '01001110001', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 2],
        ['reservation_reference' => 'P-1', 'guest_first_name' => 'Second', 'guest_phone' => '01001110002'],
    ]);
    $import = mpImport($this, [
        ['reservation_reference' => 'P-2', 'guest_phone' => '01001110003', 'is_primary' => 'yes', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 2],
        ['reservation_reference' => 'P-2', 'guest_phone' => '01001110004', 'is_primary' => 'yes'],
    ]);

    expect(mpReservations($this)->sole()->guest->first_name)->toBe('First')
        ->and($import->invalid)->toBe(2)
        ->and(imRows($import)[2]->fields)->toBe(['is_primary']);
});

it('rejects a whole reservation whose rows disagree, naming the field', function () {
    $import = mpImport($this, [
        ['reservation_reference' => 'D-1', 'guest_phone' => '01001110001', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['reservation_reference' => 'D-1', 'guest_phone' => '01001110002', 'arrival_date' => '2026-11-02', 'departure_date' => '2026-11-03'],
    ]);

    expect($import->invalid)->toBe(2)
        ->and(imRows($import)[3]->fields)->toBe(['arrival_date'])
        ->and(mpReservations($this))->toHaveCount(0);
});

it('counts unnumbered rows of one type as one room unless room_count says otherwise', function () {
    $guests = fn (string $ref, array $extra = []) => [
        ['reservation_reference' => $ref, 'guest_phone' => '0100100'.$ref[1].'001', 'room_type' => 'Deluxe', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 3, ...$extra],
        ['reservation_reference' => $ref, 'guest_phone' => '0100100'.$ref[1].'002', 'room_type' => 'Deluxe'],
        ['reservation_reference' => $ref, 'guest_phone' => '0100100'.$ref[1].'003', 'room_type' => 'deluxe'],
    ];

    mpImport($this, [...$guests('A1'), ...$guests('B2', ['room_count' => 2])]);
    $conflict = mpImport($this, [
        ['reservation_reference' => 'C3', 'guest_phone' => '01001003001', 'room_type' => 'Deluxe', 'room_count' => 1, 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['reservation_reference' => 'C3', 'guest_phone' => '01001003002', 'room_type' => 'Deluxe', 'room_count' => 2],
    ]);

    $byRef = mpReservations($this)->keyBy('reservation_id');
    expect($byRef['A1']->reservationRooms)->toHaveCount(1)
        ->and($byRef['A1']->reservationGuests)->toHaveCount(3)
        ->and($byRef['B2']->reservationRooms)->toHaveCount(2)
        ->and($conflict->invalid)->toBe(2)
        ->and(imRows($conflict)[2]->fields)->toBe(['room_count']);
});

// --- Party and ages (FR-009, FR-013a) ----------------------------------------

it('holds every named guest in the party, defaulting a missing party to the guests', function () {
    $tooMany = mpImport($this, [
        ['reservation_reference' => 'G-1', 'guest_phone' => '01001110001', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 1, 'children' => 0],
        ['reservation_reference' => 'G-1', 'guest_phone' => '01001110002'],
        ['reservation_reference' => 'G-1', 'guest_phone' => '01001110003'],
    ]);
    mpImport($this, [
        ['reservation_reference' => 'G-2', 'guest_phone' => '01001110004', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['reservation_reference' => 'G-2', 'guest_phone' => '01001110005'],
        ['reservation_reference' => 'G-2', 'guest_phone' => '01001110006'],
    ]);

    $reservation = mpReservations($this)->sole();
    expect($tooMany->invalid)->toBe(3)
        ->and(imRows($tooMany)[2]->fields)->toBe(['adults', 'children'])
        ->and($reservation->reservation_id)->toBe('G-2')
        ->and($reservation->adults)->toBe(3)
        ->and($reservation->children)->toBe(0);
});

it('requires one age per child when ages are given, and keeps children without ages', function () {
    $import = mpImport($this, [
        ['guest_phone' => '01001110001', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 2, 'children' => 2, 'children_ages' => '5'],
        ['guest_phone' => '01001110002', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 2, 'children' => 2],
    ]);

    $reservation = mpReservations($this)->sole();
    expect(imRows($import)[2]->outcome)->toBe(ImportRowOutcome::INVALID)
        ->and(imRows($import)[2]->fields)->toBe(['children_ages', 'children'])
        ->and($reservation->children)->toBe(2)
        ->and($reservation->children_ages)->toBe([]);
});

// --- Statuses against dates (FR-017–FR-017c) ----------------------------------

it('records a past no-show that holds no room and creates no task', function () {
    $hotel = mpHotel($this);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::resolveFor($hotel->id, 'Deluxe')->id, 'room_number' => '401']);

    mpImport($this, [['guest_phone' => '01001110001', 'room_number' => '401', 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-25', 'status' => 'No Show']]);

    $reservation = mpReservations($this)->sole();
    $grid = app(AvailabilityService::class)->forHotel($hotel, '2026-10-23', '2026-10-24');

    expect($reservation->status)->toBe(ReservationStatus::NO_SHOW)
        ->and(Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->pluck('status')->all())->toBe([StayStatus::NO_SHOW])
        ->and($grid['room_types'][0]['nights'][0]['booked'])->toBe(0)
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(0)
        ->and($room->fresh()->status?->value)->not->toBe('occupied');
});

it('rejects statuses the dates make impossible', function (string $status, string $arrival, string $departure, string $field) {
    $import = mpImport($this, [['guest_phone' => '01001110001', 'arrival_date' => $arrival, 'departure_date' => $departure, 'status' => $status]]);

    expect($import->invalid)->toBe(1)
        ->and(imRows($import)[2]->fields)->toBe(['status', $field])
        ->and(imRows($import)[2]->reason)->toContain($status);
})->with([
    'no-show before arrival' => ['No Show', '2026-10-30', '2026-11-02', 'arrival_date'],
    'checked out before departure' => ['Checked Out', '2026-10-20', '2026-10-24', 'departure_date'],
    'checked in before arrival' => ['In House', '2026-10-30', '2026-11-02', 'arrival_date'],
]);

it('imports a stale "in house" as checked out, with a warning, occupying nothing', function () {
    $hotel = mpHotel($this);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::resolveFor($hotel->id, 'Deluxe')->id, 'room_number' => '402']);

    $import = mpImport($this, [['guest_phone' => '01001110001', 'room_number' => '402', 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-15', 'status' => 'In House']]);

    expect(mpReservations($this)->sole()->status)->toBe(ReservationStatus::CHECKED_OUT)
        ->and(imRows($import)[2]->warnings[0]['code'])->toBe('status_corrected')
        ->and($room->fresh()->status?->value)->not->toBe('occupied');
});

it('checks in a guest in house now and occupies their room; warns when no room is named', function () {
    $hotel = mpHotel($this);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::resolveFor($hotel->id, 'Deluxe')->id, 'room_number' => '403']);

    $import = mpImport($this, [
        ['guest_phone' => '01001110001', 'room_number' => '403', 'arrival_date' => '2026-10-21', 'departure_date' => '2026-10-24', 'status' => 'In House'],
        ['guest_phone' => '01001110002', 'room_type' => 'Deluxe', 'arrival_date' => '2026-10-21', 'departure_date' => '2026-10-24', 'status' => 'In House'],
    ]);

    expect(mpReservations($this)->pluck('status')->unique()->all())->toBe([ReservationStatus::CHECKED_IN])
        ->and($room->fresh()->status->value)->toBe('occupied')
        ->and(imRows($import)[3]->warnings[0]['code'])->toBe('no_room_assigned');
});

it('writes no ledger rows, tasks, notifications or memory extraction for imported history', function () {
    Notification::fake();
    Queue::fake([ExtractGuestMemoriesJob::class]);

    mpImport($this, [
        ['guest_phone' => '01001110001', 'room_number' => '404', 'arrival_date' => '2026-10-01', 'departure_date' => '2026-10-04', 'status' => 'Checked Out', 'reservation_value' => 900],
        ['guest_phone' => '01001110002', 'room_number' => '405', 'arrival_date' => '2026-10-21', 'departure_date' => '2026-10-25', 'status' => 'In House'],
    ]);

    expect(mpReservations($this))->toHaveCount(2)
        ->and(Transaction::withoutGlobalScope('hotel')->count())->toBe(0)
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(0);
    Notification::assertNothingSent();
    Queue::assertNotPushed(ExtractGuestMemoriesJob::class);
});

// --- Room clashes (FR-012a, SC-008) -------------------------------------------

it('leaves the later of two reservations on one room without the room, naming the first', function () {
    $hotel = mpHotel($this);
    $existing = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => RoomType::resolveFor($hotel->id, 'Deluxe')->id, 'room_number' => '302']);
    createReservationWithRooms($hotel, [['room_id' => $existing->id]], ['reservation_id' => 'HELD-1', 'arrival_date' => '2026-11-05', 'departure_date' => '2026-11-08']);

    $import = mpImport($this, [
        ['reservation_reference' => 'F-1', 'guest_phone' => '01001110001', 'room_number' => '301', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-04', 'status' => 'confirmed'],
        ['reservation_reference' => 'F-2', 'guest_phone' => '01001110002', 'room_number' => '301', 'arrival_date' => '2026-11-03', 'departure_date' => '2026-11-06', 'status' => 'confirmed'],
        ['reservation_reference' => 'F-3', 'guest_phone' => '01001110003', 'room_number' => '302', 'arrival_date' => '2026-11-06', 'departure_date' => '2026-11-07', 'status' => 'confirmed'],
        ['reservation_reference' => 'F-4', 'guest_phone' => '01001110004', 'room_number' => '301', 'arrival_date' => '2026-11-04', 'departure_date' => '2026-11-05', 'status' => 'confirmed'],
    ]);

    $rows = imRows($import);
    $line = fn (string $ref) => ReservationRoom::withoutGlobalScope('hotel')->whereHas('reservation', fn ($q) => $q->withoutGlobalScope('hotel')->where('reservation_id', $ref))->sole();

    expect($import->imported)->toBe(4)
        ->and($line('F-1')->room_id)->not->toBeNull()
        ->and($line('F-2')->room_id)->toBeNull()
        ->and($rows[3]->warnings[0]['code'])->toBe('room_clash')
        ->and($rows[3]->warnings[0]['message'])->toContain('F-1')
        ->and($line('F-3')->room_id)->toBeNull()
        ->and($rows[4]->warnings[0]['message'])->toContain('HELD-1')
        ->and($line('F-4')->room_id)->toBe($line('F-1')->room_id);

    // SC-008: no room on two live lines on any night.
    $assigned = ReservationRoom::withoutGlobalScope('hotel')->whereNotNull('room_id')->with('reservation')->get();
    foreach ($assigned as $a) {
        foreach ($assigned as $b) {
            if ($a->id !== $b->id && $a->room_id === $b->room_id) {
                expect($a->reservation->arrival_date < $b->reservation->departure_date && $a->reservation->departure_date > $b->reservation->arrival_date)->toBeFalse();
            }
        }
    }
});

it('imports over-capacity and sold-out reservations as they are (FR-010)', function () {
    $hotel = mpHotel($this);
    $type = RoomType::resolveFor($hotel->id, 'Tiny');
    $type->update(['max_occupancy' => 1, 'adult_capacity' => 1]);
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_number' => '501']);

    $import = mpImport($this, [
        ['guest_phone' => '01001110001', 'room_type' => 'Tiny', 'adults' => 4, 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['guest_phone' => '01001110002', 'room_type' => 'Tiny', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['guest_phone' => '01001110003', 'room_type' => 'Tiny', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
    ]);

    expect($import->imported)->toBe(3);
});

// --- Bookings (FR-015) ------------------------------------------------------

it('attaches bookings to known activities with a status that follows the reservation', function () {
    $hotel = mpHotel($this);
    abActivity($hotel, ['name' => 'Sunset Cruise', 'price' => 50]);

    $import = mpImport($this, [
        ['reservation_reference' => 'B-1', 'guest_phone' => '01001110001', 'arrival_date' => '2026-10-01', 'departure_date' => '2026-10-04', 'status' => 'Checked Out', 'activity_name' => 'sunset cruise', 'activity_date' => '2026-10-02', 'activity_time' => '18:30'],
        ['reservation_reference' => 'B-2', 'guest_phone' => '01001110002', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-04', 'status' => 'Confirmed', 'activity_name' => 'Sunset Cruise', 'activity_date' => '2026-11-02'],
        ['reservation_reference' => 'B-3', 'guest_phone' => '01001110003', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-04', 'status' => 'Cancelled', 'activity_name' => 'Sunset Cruise', 'activity_date' => '2026-11-02'],
        ['reservation_reference' => 'B-4', 'guest_phone' => '01001110004', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-04', 'status' => 'Confirmed', 'activity_name' => 'Camel Ride', 'activity_date' => '2026-11-02'],
    ]);

    $bookings = Booking::withoutGlobalScope('hotel')->with('reservation')->get()->keyBy(fn ($booking) => $booking->reservation->reservation_id);

    expect($import->imported)->toBe(4)
        ->and($bookings)->toHaveCount(3)
        ->and($bookings['B-1']->status)->toBe(BookingStatus::REALISED)
        ->and($bookings['B-1']->origin)->toBe(BookingOrigin::IMPORT)
        ->and($bookings['B-1']->scheduled_time)->toStartWith('18:30')
        ->and($bookings['B-2']->status)->toBe(BookingStatus::CONFIRMED)
        ->and($bookings['B-3']->status)->toBe(BookingStatus::CANCELLED)
        ->and(imRows($import)[5]->outcome)->toBe(ImportRowOutcome::IMPORTED)
        ->and(imRows($import)[5]->warnings[0]['code'])->toBe('booking_unresolved');
});

it('reports an imported guest with no phone and refuses a guest with no identifier', function () {
    $import = mpImport($this, [
        ['guest_first_name' => 'Mail Only', 'guest_email' => 'mail@example.com', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
        ['guest_first_name' => 'Nobody', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03'],
    ]);

    expect(imRows($import)[2]->outcome)->toBe(ImportRowOutcome::IMPORTED)
        ->and(imRows($import)[2]->warnings[0]['code'])->toBe('no_phone')
        ->and(imRows($import)[3]->outcome)->toBe(ImportRowOutcome::INVALID)
        ->and(Guest::withoutGlobalScope('hotel')->whereNull('phone_number')->sole()->email)->toBe('mail@example.com');
});

it('takes the number of children from their ages when the count is missing', function () {
    mpImport($this, [['guest_phone' => '01001110001', 'arrival_date' => '2026-11-01', 'departure_date' => '2026-11-03', 'adults' => 2, 'children_ages' => '4,7']]);

    $reservation = mpReservations($this)->sole();
    expect($reservation->children)->toBe(2)
        ->and($reservation->children_ages)->toBe([4, 7]);
});
