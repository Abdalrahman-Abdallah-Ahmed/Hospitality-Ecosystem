<?php

use App\Ai\Tools\GetReservationsTool;
use App\Ai\Tools\GetTasksTool;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\StaffRole;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Reservations\ReservationCommands;
use App\Services\StayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-055 User Stories 1, 5 and 6 (reads): the Admin AI answers from the
 * same data the staff screens show, at most 50 records with the total
 * (FR-005–FR-007), in the hotel's own dates (R6), never with secrets or
 * costs (FR-020, FR-021).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

it('lists reservations by arrival date, status, guest and code', function () {
    $s = aatSeed();
    $tz = $s['hotel']->timezone;
    $later = aatReservation($s['hotel'], $s['type'], [null], [
        'arrival_date' => now($tz)->addDays(5)->toDateString(),
        'departure_date' => now($tz)->addDays(7)->toDateString(),
    ]);
    $tool = aatTool($s['admin'], 'GetReservationsTool');

    $codes = fn (array $result) => collect($result['items'])->pluck('reservation_id')->all();

    expect($codes(aatCall($tool, ['arrival_from' => now($tz)->addDays(5)->toDateString(), 'arrival_to' => now($tz)->addDays(5)->toDateString()])))->toBe([$later->reservation_id])
        ->and($codes(aatCall($tool, ['guest' => 'Alphason'])))->toBe([$s['code']])
        ->and($codes(aatCall($tool, ['code' => $later->reservation_id])))->toBe([$later->reservation_id])
        ->and($codes(aatCall($tool, ['staying_on' => now($tz)->addDays(6)->toDateString()])))->toBe([$later->reservation_id])
        ->and($codes(aatCall($tool, ['status' => 'cancelled'])))->toBe([]);

    // No filter: today's arrivals, and each says whether a room is assigned.
    $today = aatCall($tool, []);

    expect($codes($today))->toBe([$s['code']])
        ->and($today['items'][0]['rooms'][0])->toMatchArray(['room_type' => 'Deluxe', 'room_number' => null]);
});

it('keeps the Insights view of reservations and tasks exactly as it was', function () {
    $s = aatSeed();

    $reservations = json_decode((string) (new GetReservationsTool($s['hotel']))->handle(new Request([])), true);
    $tasks = json_decode((string) (new GetTasksTool($s['hotel']))->handle(new Request([])), true);

    // A plain list, not the paged shape, with the keys Insights reads.
    expect(array_is_list($reservations))->toBeTrue()
        ->and(array_keys($reservations[0]))->toBe(['id', 'reservation_id', 'guest_name', 'rooms', 'room_summary', 'status', 'adults', 'children'])
        ->and(array_is_list($tasks))->toBeTrue()
        ->and(array_keys($tasks[0]))->toBe(['id', 'title', 'description', 'status', 'priority', 'due_date', 'category', 'assigned_to']);

    foreach (range(1, 25) as $i) {
        Task::create(['hotel_id' => $s['hotel']->id, 'title' => "T{$i}", 'created_by' => 'manual', 'status' => 'pending', 'priority' => 'low']);
    }

    expect(json_decode((string) (new GetTasksTool($s['hotel']))->handle(new Request([])), true))->toHaveCount(20);
});

it('shows one reservation with its party, lines, rooms and stays', function () {
    $s = aatSeed();

    $result = aatCall(aatTool($s['admin'], 'GetReservationTool'), ['code' => $s['code']]);

    expect($result)->toMatchArray(['code' => $s['code'], 'status' => 'confirmed', 'adults' => 1, 'children' => 0])
        ->and($result['guest']['name'])->toBe('Alpha Alphason')
        ->and($result['rooms'])->toHaveCount(1)
        ->and($result['rooms'][0])->toMatchArray(['room_type' => 'Deluxe', 'room_number' => null, 'stay_status' => 'expected']);
});

it('finds guests by name, phone or email, and shows one guest with their history', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'GetGuestsTool');

    expect(aatCall($tool, ['search' => 'alphason'])['total'])->toBe(1)
        ->and(aatCall($tool, ['search' => $s['guest']->phone_number])['total'])->toBe(1)
        ->and(aatCall($tool, ['search' => $s['guest']->email])['total'])->toBe(1)
        ->and(aatCall($tool, ['search' => 'nobody-here'])['total'])->toBe(0);

    $guest = aatCall(aatTool($s['admin'], 'GetGuestTool'), ['guest' => $s['guest']->phone_number]);

    expect($guest['name'])->toBe('Alpha Alphason')
        ->and($guest['upcoming_reservations'][0]['code'])->toBe($s['code'])
        ->and($guest['bookings'][0]['reference'])->toBe($s['booking']->reference)
        ->and($guest)->not->toHaveKey('identity_hash');
});

it('lists room types and rooms, with room status and housekeeping status kept apart', function () {
    $s = aatSeed();
    $s['rooms']['101']->update(['status' => 'occupied', 'housekeeping_status' => 'dirty']);

    $types = aatCall(aatTool($s['admin'], 'GetRoomTypesTool'), []);
    $room = aatCall(aatTool($s['admin'], 'GetRoomsTool'), ['room_number' => '101'])['items'][0];

    expect($types['items'][0])->toMatchArray(['name' => 'Deluxe', 'rooms' => 3])
        ->and($room)->toMatchArray(['room_number' => '101', 'status' => 'occupied', 'housekeeping_status' => 'dirty'])
        ->and(aatCall(aatTool($s['admin'], 'GetRoomsTool'), ['housekeeping_status' => 'dirty'])['total'])->toBe(1);
});

it('returns at most 50 records, with the total, and says when the list is partial', function () {
    $s = aatSeed();
    avRooms($s['hotel'], $s['type'], 57);

    $result = aatCall(aatTool($s['admin'], 'GetRoomsTool'), []);

    expect($result)->toMatchArray(['total' => 60, 'returned' => 50, 'partial' => true])
        ->and($result['items'])->toHaveCount(50)
        ->and(aatCall(aatTool($s['admin'], 'GetRoomsTool'), ['limit' => 500])['returned'])->toBe(50)
        ->and(aatCall(aatTool($s['admin'], 'GetRoomsTool'), ['limit' => 5])['returned'])->toBe(5);
});

it('filters tasks and shows the housekeeping board, maintenance and bookings', function () {
    $s = aatSeed();
    $hotel = $s['hotel'];
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Engineering']);
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'name' => 'Plumbing']);
    $hotel->update(['maintenance_team_id' => $team->id]);
    Task::create(['hotel_id' => $hotel->id, 'room_id' => $s['rooms']['102']->id, 'assigned_to_team_id' => $team->id, 'task_category_id' => $category->id, 'title' => 'Leaking tap', 'created_by' => 'manual', 'status' => 'pending', 'priority' => 'high']);
    Task::create(['hotel_id' => $hotel->id, 'assigned_to_team_id' => $team->id, 'title' => 'Old repair', 'created_by' => 'manual', 'status' => 'completed', 'priority' => 'low']);
    $s['rooms']['103']->forceFill(['status' => 'out_of_order', 'out_of_order_reason' => 'Mould', 'out_of_order_until' => now()->addDays(3)->toDateString()])->save();

    $tasks = aatCall(aatTool($s['admin'], 'GetTasksTool'), ['team' => 'engineering', 'priority' => 'high']);
    $maintenance = aatCall(aatTool($s['admin'], 'GetMaintenanceTool'), []);
    $board = aatCall(aatTool($s['admin'], 'GetHousekeepingBoardTool'), []);
    $bookings = aatCall(aatTool($s['admin'], 'GetBookingsTool'), ['activity' => 'Alpha tour']);

    expect(collect($tasks['items'])->pluck('title')->all())->toBe(['Leaking tap'])
        ->and(collect($maintenance['items'])->pluck('title')->all())->toBe(['Leaking tap'])
        ->and($maintenance['out_of_order_rooms'][0])->toMatchArray(['room_number' => '103', 'reason' => 'Mould'])
        ->and($board['counts']['out_of_order'])->toBe(1)
        ->and($board['total'])->toBe(3)
        ->and($bookings['items'][0])->toMatchArray(['reference' => $s['booking']->reference, 'cancellation_requested' => false]);
});

it('reads "today" as the hotel\'s today, not the server\'s', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));

    $s = aatSeed('Alpha', 'Asia/Dubai');
    $dubaiToday = now('Asia/Dubai')->toDateString();

    expect($dubaiToday)->toBe('2026-10-10')
        ->and(aatCall(aatTool($s['admin'], 'GetReservationsTool'), [])['items'][0]['arrival_date'])->toBe($dubaiToday);

    Carbon::setTestNow();
});

it('shows staff, roles and effective permissions without a single secret', function () {
    $s = aatSeed();
    $role = StaffRole::create(['hotel_id' => $s['hotel']->id, 'name' => 'Front Desk', 'permissions' => [Permission::STAYS_CHECK_IN->value]]);
    $sara = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $s['hotel']->id, 'staff_role_id' => $role->id, 'name' => 'Sara Desk']);
    $sara->createToken('device');

    $result = aatCall(aatTool($s['admin'], 'GetStaffTool'), ['role' => 'front desk']);
    $raw = json_encode($result);

    expect(collect($result['items'])->pluck('name')->all())->toBe(['Sara Desk'])
        ->and($result['items'][0]['permissions'])->toBe(['stays.check_in'])
        ->and(collect($result['staff_roles'])->firstWhere('name', 'Front Desk')['permissions'])->toBe(['stays.check_in'])
        ->and($raw)->not->toContain('password')
        ->and($raw)->not->toContain('remember_token')
        ->and($raw)->not->toContain('$2y$')
        ->and($raw)->not->toContain('token');
});

it('shows only allow-listed hotel settings, dropping anything that looks like a secret', function () {
    $s = aatSeed();
    $s['hotel']->update(['ai_preferences' => ['tone' => 'warm', 'api_key' => 'sk-live-123', 'nested' => ['webhook_secret' => 'x', 'emoji' => false]]]);

    $settings = aatCall(aatTool($s['admin'], 'GetHotelSettingsTool'), []);

    expect($settings)->toHaveKeys(['name', 'timezone', 'currency', 'inspection_required', 'housekeeping_team'])
        ->and($settings['ai_preferences'])->toBe(['tone' => 'warm', 'nested' => ['emoji' => false]])
        ->and(json_encode($settings))->not->toContain('sk-live-123');
});

it('reports dashboard, occupancy per night, conversion without the ledger, insights, and usage without cost', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'GetReportTool');
    $tz = $s['hotel']->timezone;

    $dashboard = aatCall($tool, ['report' => 'dashboard']);
    $occupancy = aatCall($tool, ['report' => 'occupancy', 'from' => now($tz)->toDateString(), 'to' => now($tz)->addDays(2)->toDateString()]);
    $conversion = aatCall($tool, ['report' => 'conversion']);
    $usage = aatCall($tool, ['report' => 'usage']);

    expect($dashboard['arrivals_today'])->toBe([$s['code']])
        ->and($dashboard['occupancy']['total_rooms'])->toBe(3)
        ->and($occupancy['nights'])->toHaveCount(3)
        ->and($occupancy['nights'][0])->toHaveKeys(['date', 'occupied', 'total', 'percentage'])
        ->and($conversion)->toHaveKeys(['booking_rate', 'realisation_rate'])
        ->and($conversion)->not->toHaveKeys(['settlement_rate', 'settled_value', 'settled_value_by_currency'])
        ->and(aatCall($tool, ['report' => 'insights']))->toHaveKey('insights');

    // No field carries a cost or a margin. ("cost_driver" is a feature's
    // category label, the same one GET /api/usage shows hotel admins.)
    $keys = [];
    array_walk_recursive($usage, function ($value, $key) use (&$keys) {
        $keys[] = (string) $key;
    });
    $fieldNames = collect($usage)->keys()->merge(collect($usage['features'] ?? [])->flatMap(fn ($feature) => array_keys((array) $feature)))->merge($keys);

    expect($usage)->toHaveKeys(['features', 'seats'])
        ->and($fieldNames->filter(fn (string $key) => preg_match('/cost|margin|price|usd/i', $key))->all())->toBe([]);

    expect(aatCall($tool, ['report' => 'occupancy', 'from' => '2026-01-01', 'to' => '2026-03-01']))->toContain('at most 31 nights');
});

it('matches the dashboard endpoint\'s occupancy for each night', function () {
    $s = aatSeed();
    $lifecycle = app(StayLifecycleService::class);
    app(ReservationCommands::class)->assignRooms($s['reservation'], [$s['reservation']->reservationRooms()->first()->id => $s['rooms']['101']->id]);
    $lifecycle->checkInReservation($s['reservation']->fresh());

    $tomorrow = now($s['hotel']->timezone)->addDay()->toDateString();
    $night = aatCall(aatTool($s['admin'], 'GetReportTool'), ['report' => 'occupancy', 'from' => $tomorrow, 'to' => $tomorrow])['nights'][0];

    $endpoint = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($s['admin'], 'sanctum')
        ->getJson("/api/dashboard?date={$tomorrow}")->json('body.occupancy');

    expect($night['occupied'])->toBe($endpoint['occupied_rooms'])
        ->and($night['total'])->toBe($endpoint['total_rooms'])
        ->and($night['occupied'])->toBe(1);
});
