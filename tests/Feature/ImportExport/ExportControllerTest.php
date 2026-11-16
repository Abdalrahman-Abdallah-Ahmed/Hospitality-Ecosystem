<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| List exports (spec 021): reservations, activities, bookings and guests as
| Excel, CSV or SQL, filtered exactly as the list is, capped, rate limited,
| audited and kept to one hotel.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function exportHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

/**
 * @return array{0: User, 1: Hotel}
 */
function exportAdmin(string $name = 'Export Harbor Hotel'): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function exportGuest(Hotel $hotel, array $attributes = []): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'external_id' => 'ext-'.Str::random(8),
        'channel' => 'booking_com',
        ...$attributes,
    ]);
}

function exportReservation(Hotel $hotel, array $attributes = []): Reservation
{
    return Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $attributes['guest_id'] ?? exportGuest($hotel)->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        ...$attributes,
    ]);
}

function exportGet(User $user, string $uri): TestResponse
{
    return test()->withHeaders(exportHeaders())->actingAs($user, 'sanctum')->get($uri);
}

/**
 * The bytes of a downloaded file, whether it was written to disk (Excel,
 * CSV) or streamed (SQL).
 */
function exportBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    if ($base instanceof BinaryFileResponse) {
        return file_get_contents($base->getFile()->getPathname());
    }

    return $response->streamedContent();
}

/**
 * @return list<list<string>>
 */
function csvRows(TestResponse $response): array
{
    $body = exportBody($response);
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, substr($body, 3));
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        if ($row !== [null]) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function xlsxSheet(TestResponse $response): Worksheet
{
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, exportBody($response));

    return IOFactory::load($path)->getActiveSheet();
}

/**
 * @return list<string>
 */
function exportColumn(array $rows, string $header): array
{
    $index = array_search($header, $rows[0], true);

    return array_map(fn (array $row) => $row[$index], array_slice($rows, 1));
}

function exportEmployee(Hotel $hotel, array $permissions): User
{
    $role = StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);
}

function exportEvents(string $type)
{
    return EventLog::withoutGlobalScopes()->where('event_type', $type)->get();
}

/*
| Reservations (User Story 1)
*/

it('exports the filtered reservations as CSV in the list order across every page', function () {
    [$admin, $hotel] = exportAdmin();

    foreach (range(1, 25) as $i) {
        exportReservation($hotel, [
            'reservation_id' => sprintf('RES-%03d', $i),
            'arrival_date' => now()->addDays(30 - $i)->toDateString(),
            'departure_date' => now()->addDays(33 - $i)->toDateString(),
            'status' => $i % 5 === 0 ? 'pending' : 'confirmed',
        ]);
    }

    $listed = collect(range(1, 2))->flatMap(fn (int $page) => exportGet($admin, "/api/reservation?filter[status]=confirmed&sort=arrival_date&per_page=10&page={$page}")
        ->assertOk()->json('body.data'))->pluck('reservation_id')->all();

    $response = exportGet($admin, '/api/reservation/export?filter[status]=confirmed&sort=arrival_date&format=csv')
        ->assertOk();

    $rows = csvRows($response);

    expect($listed)->toHaveCount(20)
        ->and($rows[0])->toBe([
            'Reservation Number', 'Guest Name', 'Guest Email', 'Guest Phone', 'Arrival Date', 'Departure Date',
            'Nights', 'Adults', 'Children', 'Room Types', 'Rooms', 'Party Guests', 'Status', 'Source',
            'Reservation Value', 'Currency', 'Special Requests', 'Created At',
        ])
        ->and(exportColumn($rows, 'Reservation Number'))->toBe($listed)
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toMatch('/filename="?reservations-export-harbor-hotel-\d{8}-\d{4}\.csv"?/');
});

it('writes CSV when no format is given and header only when nothing matches', function () {
    [$admin] = exportAdmin();

    $rows = csvRows(exportGet($admin, '/api/reservation/export')->assertOk());

    expect($rows)->toHaveCount(1)->and($rows[0][0])->toBe('Reservation Number');
});

it('exports reservations to Excel with real dates and numbers', function () {
    [$admin, $hotel] = exportAdmin();
    exportReservation($hotel, ['reservation_id' => 'RES-XLSX', 'reservation_value' => 450.5, 'special_requests' => '=HYPERLINK("x")']);

    $response = exportGet($admin, '/api/reservation/export?format=xlsx')->assertOk();
    $sheet = xlsxSheet($response);

    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($sheet->getTitle())->toBe('Reservations')
        ->and($sheet->getCell('A2')->getValue())->toBe('RES-XLSX')
        ->and($sheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and(Date::isDateTime($sheet->getCell('E2')))->toBeTrue()
        ->and($sheet->getCell('G2')->getValue())->toBe(3)
        ->and($sheet->getCell('O2')->getValue())->toEqual(450.5)
        // Stored as text, never as a formula (FR-011).
        ->and($sheet->getCell('Q2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('Q2')->getValue())->toBe('=HYPERLINK("x")');
});

it('puts rooms, room types and party guests of one reservation in one row', function () {
    [$admin, $hotel] = exportAdmin();
    $deluxe = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Deluxe', 'max_occupancy' => 2, 'adult_capacity' => 2, 'child_capacity' => 0, 'base_price' => 100]);
    $suite = RoomType::create(['hotel_id' => $hotel->id, 'name' => 'Suite', 'max_occupancy' => 2, 'adult_capacity' => 2, 'child_capacity' => 0, 'base_price' => 200]);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $deluxe->id, 'room_number' => '101']);

    $lead = exportGuest($hotel, ['first_name' => 'Lead', 'last_name' => 'Guest']);
    $reservation = createReservationWithRooms($hotel, [
        ['room_type_id' => $deluxe->id, 'room_id' => $room->id],
        ['room_type_id' => $suite->id],
        ['room_type_id' => $suite->id, 'status' => 'cancelled'],
    ], ['guest_id' => $lead->id, 'reservation_id' => 'RES-PARTY']);

    foreach ([$lead, exportGuest($hotel, ['first_name' => 'Second', 'last_name' => 'Traveller'])] as $i => $guest) {
        ReservationGuest::create(['hotel_id' => $hotel->id, 'reservation_id' => $reservation->id, 'guest_id' => $guest->id, 'is_primary' => $i === 0]);
    }

    createReservationWithRooms($hotel, [['room_type_id' => $deluxe->id]], ['reservation_id' => 'RES-NOROOM']);

    $rows = csvRows(exportGet($admin, '/api/reservation/export?sort=reservation_id')->assertOk());

    expect($rows)->toHaveCount(3)
        ->and(exportColumn($rows, 'Reservation Number'))->toBe(['RES-NOROOM', 'RES-PARTY'])
        ->and(exportColumn($rows, 'Room Types'))->toBe(['Deluxe', 'Deluxe, Suite'])
        ->and(exportColumn($rows, 'Rooms'))->toBe(['', '101'])
        ->and(exportColumn($rows, 'Party Guests')[1])->toBe('Lead Guest, Second Traveller');
});

it('keeps non-Latin text, neutralises formulas and keeps awkward values in one CSV field', function () {
    [$admin, $hotel] = exportAdmin();

    foreach (['José Müller', 'أحمد', 'Иван'] as $i => $name) {
        exportReservation($hotel, [
            'reservation_id' => "RES-{$i}",
            'guest_id' => exportGuest($hotel, ['first_name' => $name, 'last_name' => null])->id,
            'special_requests' => $i === 0 ? "Late, \"quiet\"\nroom" : '=HYPERLINK("x")',
        ]);
    }

    $rows = csvRows(exportGet($admin, '/api/reservation/export?sort=reservation_id')->assertOk());

    expect(exportColumn($rows, 'Guest Name'))->toBe(['José Müller', 'أحمد', 'Иван'])
        ->and(exportColumn($rows, 'Special Requests'))->toBe(["Late, \"quiet\"\nroom", '\'=HYPERLINK("x")', '\'=HYPERLINK("x")']);
});

it('refuses unknown formats, filters and sort columns', function (string $query, string $field) {
    [$admin] = exportAdmin();

    $this->withHeaders(exportHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/reservation/export?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'format' => ['format=pdf', 'format'],
    'filter' => ['filter[nope]=1', 'filter.nope'],
    'sort' => ['sort=nope', 'sort'],
]);

it('refuses an export above the row cap and accepts one at it', function () {
    [$admin, $hotel] = exportAdmin();
    config(['exports.max_rows' => 5]);

    foreach (range(1, 5) as $i) {
        exportReservation($hotel);
    }

    exportGet($admin, '/api/reservation/export')->assertOk();

    exportReservation($hotel);

    $this->withHeaders(exportHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/reservation/export?format=xlsx')
        ->assertUnprocessable()
        ->assertJsonPath('body.matching', 6)
        ->assertJsonPath('body.max_rows', 5);

    $refused = exportEvents('hotel.reservations_export_refused');

    expect($refused)->toHaveCount(1)
        ->and($refused->first()->changes['matching'])->toBe(6)
        ->and($refused->first()->changes['format'])->toBe('xlsx');
});

it('records each export in the audit log without record content', function () {
    [$admin, $hotel] = exportAdmin();
    exportReservation($hotel, ['status' => 'confirmed', 'special_requests' => 'Secret request']);

    exportGet($admin, '/api/reservation/export?filter[status]=confirmed&format=csv')->assertOk();

    $events = exportEvents('hotel.reservations_exported');

    expect($events)->toHaveCount(1)
        ->and($events->first()->actor_id)->toBe($admin->id)
        ->and($events->first()->hotel_id)->toBe($hotel->id)
        ->and($events->first()->changes)->toMatchArray([
            'format' => 'csv',
            'filter' => ['status' => 'confirmed'],
            'row_count' => 1,
        ])
        ->and(json_encode($events->first()->changes))->not->toContain('Secret request');
});

it('allows ten exports a minute per user, shared across modules', function () {
    [$admin] = exportAdmin();

    foreach (['reservation', 'reservation', 'reservation', 'reservation', 'guest', 'guest', 'guest', 'booking', 'booking', 'booking'] as $module) {
        exportGet($admin, "/api/{$module}/export")->assertOk();
    }

    exportGet($admin, '/api/activity/export')->assertTooManyRequests();
});

it('keeps another hotel out of a reservation export', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    exportReservation($hotel, ['reservation_id' => 'RES-MINE']);
    $theirs = exportReservation($other, ['reservation_id' => 'RES-THEIRS']);
    exportReservation($hotel, ['reservation_id' => 'RES-GONE'])->delete();

    $all = csvRows(exportGet($admin, '/api/reservation/export')->assertOk());
    $byTheirGuest = csvRows(exportGet($admin, "/api/reservation/export?filter[guest_id]={$theirs->guest_id}")->assertOk());
    $spoofed = csvRows(exportGet($admin, "/api/reservation/export?hotel_id={$other->id}")->assertOk());

    expect(exportColumn($all, 'Reservation Number'))->toBe(['RES-MINE'])
        ->and($byTheirGuest)->toHaveCount(1)
        ->and(exportColumn($spoofed, 'Reservation Number'))->toBe(['RES-MINE']);
});

it('refuses a super admin\'s own token, named hotel or not', function () {
    [, $other] = exportAdmin('Other Hotel');
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    assertSuperAdminRefused($this->withHeaders(exportHeaders())->actingAs($superAdmin, 'sanctum')->getJson('/api/reservation/export'));
    assertSuperAdminRefused(exportGet($superAdmin, "/api/reservation/export?hotel_id={$other->id}"));
});

/*
| Guests, bookings and activities (User Story 2)
*/

it('exports guests filtered as the list is', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    exportGuest($hotel, ['first_name' => 'Vera', 'last_name' => 'Vip', 'is_vip' => true, 'email' => 'vera@example.com']);
    exportGuest($hotel, ['first_name' => 'Norm', 'last_name' => 'Al', 'is_vip' => false]);
    exportGuest($other, ['first_name' => 'Elsewhere', 'is_vip' => true]);

    $rows = csvRows(exportGet($admin, '/api/guest/export?filter[is_vip]=1')->assertOk());
    $sheet = xlsxSheet(exportGet($admin, '/api/guest/export?filter[is_vip]=1&format=xlsx')->assertOk());

    expect($rows[0])->toBe(['First Name', 'Last Name', 'Email', 'Phone', 'Preferred Language', 'Nationality', 'Loyalty Status', 'VIP', 'Channel', 'External ID', 'Created At'])
        ->and(exportColumn($rows, 'First Name'))->toBe(['Vera'])
        ->and(exportColumn($rows, 'VIP'))->toBe(['Yes'])
        ->and(exportColumn($rows, 'Channel'))->toBe(['Booking Com'])
        ->and($sheet->getCell('A2')->getValue())->toBe('Vera')
        ->and($sheet->getCell('A3')->getValue())->toBeNull()
        ->and(exportEvents('hotel.guests_exported'))->toHaveCount(2);
});

it('exports bookings with names instead of ids and the booking-only filters', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    $guest = exportGuest($hotel, ['first_name' => 'Bea', 'last_name' => 'Booker']);
    $reservation = exportReservation($hotel, ['guest_id' => $guest->id, 'reservation_id' => 'RES-BOOK']);
    $cruise = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Sunset cruise', 'price' => 60, 'currency' => 'USD']);
    $gone = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Old tour', 'price' => 10, 'currency' => 'USD']);

    $kept = wp5Booking($hotel, ['guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'activity_id' => $cruise->id, 'item_name' => 'Sunset cruise', 'scheduled_date' => '2026-12-10']);
    wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $gone->id, 'item_name' => 'Old tour', 'scheduled_date' => '2026-12-20']);
    wp5Booking($other, ['guest_id' => exportGuest($other)->id, 'item_name' => 'Elsewhere', 'scheduled_date' => '2026-12-10']);
    $gone->delete();

    $all = csvRows(exportGet($admin, '/api/booking/export')->assertOk());
    $dated = csvRows(exportGet($admin, '/api/booking/export?scheduled_from=2026-12-01&scheduled_to=2026-12-15')->assertOk());
    $listed = collect(exportGet($admin, '/api/booking?per_page=100')->json('body.data'))->pluck('reference')->all();

    expect(exportColumn($all, 'Reference'))->toBe($listed)
        ->and(exportColumn($all, 'Item Name'))->toBe(['Sunset cruise', 'Old tour'])
        ->and(exportColumn($all, 'Activity'))->toBe(['Sunset cruise', ''])
        ->and(exportColumn($all, 'Guest Name'))->toBe(['Bea Booker', 'Bea Booker'])
        ->and(exportColumn($all, 'Reservation Number'))->toBe(['RES-BOOK', ''])
        ->and(exportColumn($dated, 'Reference'))->toBe([$kept->reference])
        ->and(exportEvents('hotel.bookings_exported'))->toHaveCount(2);
});

it('exports the activity catalogue with categories, numbers and dates', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    $category = ActivityCategory::create(['hotel_id' => $hotel->id, 'name' => 'Water']);
    Activity::create([
        'hotel_id' => $hotel->id, 'category_id' => $category->id, 'name' => 'Snorkel', 'price' => 35.5, 'currency' => 'USD',
        'is_active' => true, 'available_from' => '2026-06-01', 'duration_days' => 1, 'daily_capacity' => 12,
    ]);
    Activity::create(['hotel_id' => $other->id, 'name' => 'Elsewhere', 'price' => 1, 'currency' => 'USD']);

    $rows = csvRows(exportGet($admin, '/api/activity/export')->assertOk());
    $sheet = xlsxSheet(exportGet($admin, '/api/activity/export?format=xlsx')->assertOk());

    expect(exportColumn($rows, 'Name'))->toBe(['Snorkel'])
        ->and(exportColumn($rows, 'Category'))->toBe(['Water'])
        ->and(exportColumn($rows, 'Active'))->toBe(['Yes'])
        ->and(exportColumn($rows, 'Available From'))->toBe(['2026-06-01'])
        ->and(exportColumn($rows, 'Daily Capacity'))->toBe(['12'])
        ->and($sheet->getCell('D2')->getValue())->toEqual(35.5)
        ->and(Date::isDateTime($sheet->getCell('G2')))->toBeTrue()
        ->and(exportEvents('hotel.activities_exported'))->toHaveCount(2);
});

/*
| SQL (User Story 3)
*/

it('exports reservations as SQL INSERT statements in list order', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    exportReservation($hotel, ['reservation_id' => 'RES-B']);
    exportReservation($hotel, ['reservation_id' => 'RES-A']);
    exportReservation($other, ['reservation_id' => 'RES-OTHER']);

    $response = exportGet($admin, '/api/reservation/export?format=sql&sort=reservation_id')->assertOk();
    $body = exportBody($response);
    $inserts = array_values(array_filter(explode("\n", $body), fn ($line) => str_starts_with($line, 'INSERT INTO "reservations"')));

    expect($response->headers->get('Content-Type'))->toBe('application/sql; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toContain('.sql')
        ->and($inserts)->toHaveCount(2)
        ->and($inserts[0])->toContain("'RES-A'")
        ->and($inserts[1])->toContain("'RES-B'")
        ->and($body)->not->toContain('RES-OTHER')
        ->and(exportEvents('hotel.reservations_exported')->first()->changes['format'])->toBe('sql');

    $empty = exportBody(exportGet($admin, '/api/reservation/export?format=sql&filter[status]=cancelled')->assertOk());

    expect($empty)->not->toContain('INSERT INTO');
});

/*
| Review fixes
*/

it('leaves phone numbers and negative numbers alone while still neutralising formulas', function () {
    [$admin, $hotel] = exportAdmin();
    exportGuest($hotel, ['first_name' => 'Phone', 'phone_number' => '+20 123 456-7890']);
    exportGuest($hotel, ['first_name' => 'Minus', 'phone_number' => '-1+cmd|calc']);

    $rows = csvRows(exportGet($admin, '/api/guest/export?sort=first_name')->assertOk());

    expect(exportColumn($rows, 'Phone'))->toBe(["'-1+cmd|calc", '+20 123 456-7890']);
});

it('writes date-times in the hotel time zone and dates as they are', function () {
    [$admin, $hotel] = exportAdmin();
    $hotel->update(['timezone' => 'Asia/Riyadh']);
    $guest = exportGuest($hotel);
    $booking = wp5Booking($hotel, ['guest_id' => $guest->id, 'item_name' => 'Dive', 'scheduled_date' => '2026-12-10']);
    $booking->forceFill(['scheduled_for' => '2026-12-10 06:00:00'])->save();

    $rows = csvRows(exportGet($admin, '/api/booking/export')->assertOk());

    expect(exportColumn($rows, 'Scheduled For'))->toBe(['2026-12-10 09:00']);
});

it('refuses an employee without the export permission before validating the query', function () {
    [, $hotel] = exportAdmin();
    $viewer = exportEmployee($hotel, [Permission::GUESTS_VIEW]);

    $this->withHeaders(exportHeaders())->actingAs($viewer, 'sanctum')
        ->getJson('/api/guest/export?sort=nope&filter[secret]=1')
        ->assertForbidden();
});

it('reads the CSV under the hotel scope even though it streams after the request', function () {
    [$admin, $hotel] = exportAdmin();
    [, $other] = exportAdmin('Other Hotel');
    exportGuest($hotel, ['first_name' => 'Mine']);
    exportGuest($other, ['first_name' => 'Theirs']);

    $response = exportGet($admin, '/api/guest/export')->assertOk();
    TenantContext::reset();

    expect(exportColumn(csvRows($response), 'First Name'))->toBe(['Mine']);
});
