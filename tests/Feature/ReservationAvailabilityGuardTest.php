<?php

use App\Ai\Tools\CreateReservationTool;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Exceptions\InsufficientAvailabilityException;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Support\Audit\EventLogger;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request as ToolRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function gGuest(Hotel $hotel): Guest
{
    return Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(8), 'channel' => 'booking_com']);
}

/**
 * A create payload for 12–15 March 2027 (nights 12, 13, 14).
 */
function gPayload(Hotel $hotel, array $rooms, array $overrides = []): array
{
    return [
        'hotel_id' => $hotel->id,
        'guest_id' => gGuest($hotel)->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => '2027-03-12',
        'departure_date' => '2027-03-15',
        'status' => 'confirmed',
        'adults' => 1,
        'children' => 0,
        'rooms' => $rooms,
        ...$overrides,
    ];
}

function gPost($test, User $user, array $payload): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->postJson('/api/reservation', $payload);
}

function gPut($test, User $user, string $id, array $payload): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->putJson("/api/reservation/{$id}", $payload);
}

function gEmployee(Hotel $hotel, array $permissions): User
{
    $role = StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);
}

/**
 * Deluxe with `$rooms` rooms, and `$bookedOn13th` units already held on the
 * night of 13 March 2027 by another reservation.
 *
 * @return array{0: Hotel, 1: RoomType}
 */
function gDeluxe(int $rooms, int $bookedOn13th = 0): array
{
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, $rooms);

    if ($bookedOn13th > 0) {
        avBook($hotel, $deluxe, '2027-03-13', '2027-03-14', units: $bookedOn13th);
    }

    return [$hotel, $deluxe];
}

function gLiveLines(string $reservationId): int
{
    return ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $reservationId)->active()->count();
}

it('saves a reservation that fits the rooms left', function () {
    [$hotel, $deluxe] = gDeluxe(5, 2);

    gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id, 'quantity' => 3]]))->assertCreated();
});

it('rejects a reservation that oversells a night, saying which type, night and by how many, and saves nothing', function () {
    [$hotel, $deluxe] = gDeluxe(4, 2);
    $reservations = Reservation::withoutGlobalScope('hotel')->count();
    $lines = ReservationRoom::withoutGlobalScope('hotel')->count();

    gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id, 'quantity' => 3]]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.rooms.0', 'Not enough rooms available: Deluxe is short by 1 on 2027-03-13.')
        ->assertJsonPath('shortfalls', [[
            'room_type_id' => $deluxe->id,
            'room_type_name' => 'Deluxe',
            'nights' => [['date' => '2027-03-13', 'short' => 1]],
        ]]);

    expect(Reservation::withoutGlobalScope('hotel')->count())->toBe($reservations)
        ->and(ReservationRoom::withoutGlobalScope('hotel')->count())->toBe($lines);
});

it('never counts a reservation\'s own lines against it', function () {
    [$hotel, $deluxe] = gDeluxe(2);
    $id = gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id, 'quantity' => 2]]))->assertCreated()->json('body.id');
    $lineIds = ReservationRoom::where('reservation_id', $id)->pluck('id')->map(fn ($id) => ['id' => $id])->all();

    gPut($this, $hotel->owner, $id, ['special_requests' => 'Late arrival', 'rooms' => $lineIds])->assertOk();
});

it('rejects adding a line on a full night', function () {
    [$hotel, $deluxe] = gDeluxe(2);
    $id = gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id, 'quantity' => 2]]))->assertCreated()->json('body.id');
    $lines = ReservationRoom::where('reservation_id', $id)->pluck('id')->map(fn ($id) => ['id' => $id])->all();

    gPut($this, $hotel->owner, $id, ['rooms' => [...$lines, ['room_type_id' => $deluxe->id]]])
        ->assertUnprocessable()
        ->assertJsonPath('shortfalls.0.nights', [
            ['date' => '2027-03-12', 'short' => 1],
            ['date' => '2027-03-13', 'short' => 1],
            ['date' => '2027-03-14', 'short' => 1],
        ]);

    expect(gLiveLines($id))->toBe(2);
});

it('rejects extending or moving the dates onto a full night', function (array $dates) {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    avBook($hotel, $deluxe, '2027-03-15', '2027-03-16');
    $id = gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id]]))->assertCreated()->json('body.id');

    gPut($this, $hotel->owner, $id, $dates)
        ->assertUnprocessable()
        ->assertJsonPath('shortfalls.0.nights.0', ['date' => '2027-03-15', 'short' => 1]);

    expect(Reservation::find($id)->departure_date->toDateString())->toBe('2027-03-15');
})->with([
    'extended' => [['departure_date' => '2027-03-16']],
    'moved' => [['arrival_date' => '2027-03-14', 'departure_date' => '2027-03-17']],
]);

it('checks a deleted reservation re-created with the same reference like a new one', function () {
    [$hotel, $deluxe] = gDeluxe(1);
    $payload = gPayload($hotel, [['room_type_id' => $deluxe->id]]);
    $id = gPost($this, $hotel->owner, $payload)->assertCreated()->json('body.id');
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')->deleteJson("/api/reservation/{$id}")->assertOk();
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-15');

    gPost($this, $hotel->owner, $payload)->assertUnprocessable()->assertJsonPath('shortfalls.0.room_type_id', $deluxe->id);

    expect(Reservation::withTrashed()->find($id)->trashed())->toBeTrue()
        ->and(ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $id)->pluck('status')->map->value->all())->toBe(['reserved']);
});

it('does not check changes that only free rooms, even on an overbooked night', function (string $change) {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    $reservation = avBook($hotel, $deluxe, '2027-03-12', '2027-03-15', units: 2);
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-15');

    $payload = match ($change) {
        'shorter stay' => ['departure_date' => '2027-03-13'],
        'one line removed' => ['rooms' => [['id' => $reservation->reservationRooms()->value('id')]]],
        'cancelled' => ['status' => 'cancelled'],
        'special requests only' => ['special_requests' => 'Quiet room'],
    };

    gPut($this, $hotel->owner, $reservation->id, $payload)->assertOk();
})->with(['shorter stay', 'one line removed', 'cancelled', 'special requests only']);

it('confirms a pending reservation on an overbooked night, since it adds nothing', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    $pending = avBook($hotel, $deluxe, '2027-03-12', '2027-03-15', 'pending');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-15');

    gPut($this, $hotel->owner, $pending->id, ['status' => 'confirmed'])->assertOk();
});

it('refuses an overbook override from someone without reservations.overbook, before writing anything', function () {
    [$hotel, $deluxe] = gDeluxe(1);
    $clerk = gEmployee($hotel, [Permission::RESERVATIONS_CREATE]);
    $reservations = Reservation::withoutGlobalScope('hotel')->count();

    // Refused even though this booking fits: asking is what needs the permission.
    gPost($this, $clerk, gPayload($hotel, [['room_type_id' => $deluxe->id]], ['overbook_override' => true]))->assertForbidden();

    expect(Reservation::withoutGlobalScope('hotel')->count())->toBe($reservations);
});

it('saves an overbooking with the permission and audits who did it and the shortfall', function () {
    [$hotel, $deluxe] = gDeluxe(1, 1);
    $manager = gEmployee($hotel, [Permission::RESERVATIONS_CREATE, Permission::RESERVATIONS_OVERBOOK]);

    $id = gPost($this, $manager, gPayload($hotel, [['room_type_id' => $deluxe->id]], ['overbook_override' => true]))
        ->assertCreated()
        ->json('body.id');

    $event = EventLog::withoutGlobalScopes()->where('event_type', 'reservation.overbooking_overridden')->where('subject_id', $id)->sole();

    expect($event->actor_id)->toBe($manager->id)
        ->and($event->actor_kind->value ?? $event->actor_kind)->toBe('user')
        ->and($event->changes['shortfalls'])->toBe([[
            'room_type_id' => $deluxe->id,
            'room_type_name' => 'Deluxe',
            'nights' => [['date' => '2027-03-13', 'short' => 1]],
        ]]);

    $grid = app(AvailabilityService::class)->forHotel($hotel, '2027-03-13', '2027-03-14');
    expect($grid['room_types'][0]['nights'][0]['overbooked'])->toBe(1);
});

it('lets an admin overbook', function () {
    [$hotel, $deluxe] = gDeluxe(1, 1);

    gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id]], ['overbook_override' => true]))->assertCreated();
});

it('lists only the request\'s own room types as short', function () {
    [$hotel, $deluxe] = gDeluxe(1, 1);
    $suite = avType($hotel, 'Suite');
    avRooms($hotel, $suite, 1);
    avBook($hotel, $suite, '2027-03-13', '2027-03-14', units: 3);
    $family = avType($hotel, 'Family');
    avRooms($hotel, $family, 2);

    $response = gPost($this, $hotel->owner, gPayload($hotel, [['room_type_id' => $deluxe->id], ['room_type_id' => $family->id]]))
        ->assertUnprocessable();

    expect(collect($response->json('shortfalls'))->pluck('room_type_id')->all())->toBe([$deluxe->id]);
});

it('tells the AI what is short and saves nothing, with no way to override', function () {
    [$hotel, $deluxe] = gDeluxe(1, 1);

    $result = (string) (new CreateReservationTool($hotel))->handle(new ToolRequest([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'Deluxe']],
        'arrival_date' => '2027-03-12',
        'departure_date' => '2027-03-15',
        'overbook_override' => true,
    ]));

    expect($result)->toBe('Not enough rooms available: Deluxe is short by 1 on 2027-03-13.')
        ->and(Reservation::withoutGlobalScope('hotel')->where('source', 'whatsapp')->count())->toBe(0);
});

it('never lets an AI actor override, even if the flag reaches the domain operation', function () {
    [$hotel, $deluxe] = gDeluxe(1, 1);

    expect(fn () => EventLogger::asAiAgent(fn () => ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => gGuest($hotel)->id,
        'reservation_id' => 'RES-AI-1',
        'arrival_date' => '2027-03-12',
        'departure_date' => '2027-03-15',
        'status' => 'confirmed',
    ], [['room_type_id' => $deluxe->id]], overbookOverride: true)))->toThrow(InsufficientAvailabilityException::class);
});

it('records imported reservations beyond availability as they are, showing up as overbooked', function () {
    [$hotel] = gDeluxe(1);
    $path = tempnam(sys_get_temp_dir(), 'reservations').'.csv';
    file_put_contents($path, "guest_phone,arrival_date,departure_date,room_type\n555-0101,2027-03-12,2027-03-13,Deluxe\n555-0102,2027-03-12,2027-03-13,Deluxe\n555-0103,2027-03-12,2027-03-13,Deluxe");

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => new UploadedFile($path, 'reservations.csv', 'text/csv', null, true)])
        ->assertOk()
        ->assertJsonPath('body.imported', 3);

    $grid = app(AvailabilityService::class)->forHotel($hotel, '2027-03-12', '2027-03-13');

    expect($grid['room_types'][0]['nights'][0])->toMatchArray(['booked' => 3, 'sellable' => 0, 'overbooked' => 2]);
});
