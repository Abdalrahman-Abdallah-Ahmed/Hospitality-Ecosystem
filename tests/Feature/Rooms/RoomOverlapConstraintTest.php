<?php

use App\Enums\StayStatus;
use App\Models\ReservationRoom;
use App\Models\Stay;
use App\Support\Reservations\RoomAssignmentRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| The stored-data guarantee (spec 020 FR-009, research R1): the triggers keep
| `held_nights`, and `reservation_rooms_no_overlap` rejects two lines holding
| one room on a shared night, whatever writes the rows.
*/

function ocHeldNights(ReservationRoom $line): ?string
{
    return DB::table('reservation_rooms')->where('id', $line->id)->value('held_nights');
}

/**
 * Gives the line a stay with `$status`, as ReservationCreator would.
 */
function ocStay(ReservationRoom $line, StayStatus $status): Stay
{
    $reservation = $line->reservation;

    return Stay::create([
        'hotel_id' => $line->hotel_id,
        'guest_id' => $reservation->guest_id,
        'reservation_id' => $reservation->id,
        'reservation_room_id' => $line->id,
        'room_id' => $line->room_id,
        'planned_arrival_date' => $reservation->arrival_date,
        'planned_departure_date' => $reservation->departure_date,
        'status' => $status,
    ]);
}

function ocExpectClash(callable $write): void
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23P01')
            ->and($e->getMessage())->toContain('reservation_rooms_no_overlap');

        return;
    }

    throw new RuntimeException('Expected the room overlap constraint to reject the write.');
}

beforeEach(function () {
    [$this->hotel, $this->type, $this->rooms] = fdHotel(2);
    $this->room = $this->rooms[0];
    $this->held = fn (array $attributes = [], array $line = []) => createReservationWithRooms(
        $this->hotel,
        [['room_type_id' => $this->type->id, 'room_id' => $this->room->id, ...$line]],
        ['arrival_date' => '2026-11-02', 'departure_date' => '2026-11-05', ...$attributes],
    )->reservationRooms()->first();
});

it('keeps the nights of a line that holds its room', function () {
    expect(ocHeldNights(($this->held)()))->toBe('[2026-11-02,2026-11-05)');
});

it('holds nothing for a line that does not hold its room', function (array $attributes, array $line, ?Closure $after) {
    $held = ($this->held)($attributes, $line);
    $after?->call($this, $held);

    expect(ocHeldNights($held->fresh() ?? ReservationRoom::withTrashed()->find($held->id)))->toBeNull();
})->with([
    'no room' => [[], ['room_id' => null], null],
    'cancelled line' => [[], ['status' => 'cancelled'], null],
    'soft-deleted line' => [[], [], fn ($line) => $line->delete()],
    'cancelled reservation' => [['status' => 'cancelled'], [], null],
    'checked-out reservation' => [['status' => 'checked_out'], [], null],
    'no-show reservation' => [['status' => 'no_show'], [], null],
    'soft-deleted reservation' => [[], [], fn ($line) => $line->reservation->delete()],
    'stay departed' => [[], [], fn ($line) => ocStay($line, StayStatus::IN_HOUSE)->update(['status' => StayStatus::DEPARTED])],
    'stay no-show' => [[], [], fn ($line) => ocStay($line, StayStatus::NO_SHOW)],
]);

it('agrees with the holding-lines rule on a mix of states', function () {
    $cases = [
        ($this->held)(),
        ($this->held)(['status' => 'cancelled', 'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03']),
        ($this->held)(['arrival_date' => '2027-01-01', 'departure_date' => '2027-01-02'], ['status' => 'cancelled']),
        ($this->held)(['status' => 'pending', 'arrival_date' => '2027-02-01', 'departure_date' => '2027-02-03']),
    ];

    $holding = RoomAssignmentRules::holdingLines()->whereNotNull('reservation_rooms.room_id')->pluck('reservation_rooms.id')->all();

    foreach ($cases as $line) {
        expect(ocHeldNights($line) !== null)->toBe(in_array($line->id, $holding, true));
    }
});

it('rejects a second line holding the room on a shared night, written straight to the table', function () {
    $held = ($this->held)();
    $other = createReservationWithRooms($this->hotel, [], ['arrival_date' => '2026-11-04', 'departure_date' => '2026-11-06']);

    ocExpectClash(fn () => DB::table('reservation_rooms')->insert([
        'id' => (string) Str::uuid(),
        'hotel_id' => $this->hotel->id,
        'reservation_id' => $other->id,
        'room_type_id' => $this->type->id,
        'room_id' => $held->room_id,
        'status' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('accepts back-to-back bookings of one room', function () {
    ($this->held)();
    $next = ($this->held)(['arrival_date' => '2026-11-05', 'departure_date' => '2026-11-07']);

    expect(ocHeldNights($next))->toBe('[2026-11-05,2026-11-07)');
});

it('rejects moving a reservation\'s dates into a clash', function () {
    ($this->held)();
    $later = ($this->held)(['arrival_date' => '2026-11-10', 'departure_date' => '2026-11-12']);

    ocExpectClash(fn () => DB::table('reservations')->where('id', $later->reservation_id)->update(['arrival_date' => '2026-11-04']));
});

/*
| Every path that puts a room on a line refuses the same clash with the same
| words (SC-003), and the paths that bring rooms back step aside.
*/

describe('every path', function () {
    beforeEach(function () {
        putenv('API_KEY=test-api-key');
        config(['app.api_key' => 'test-api-key']);

        $this->admin = adminOfHotel($this->hotel);
        // A spare room, so availability never decides these tests.
        avRooms($this->hotel, $this->type, 2);
        $this->today = fn (int $days = 0) => now()->addDays($days)->toDateString();
        fdBook($this->hotel, $this->type, [$this->room->id], ['reservation_id' => 'RES-HOLDER']);
        $this->clash = "Room {$this->room->room_number} is booked for RES-HOLDER on overlapping nights.";
    });

    it('refuses the clash on reservation create', function () {
        $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
            ->postJson('/api/reservation', [
                'hotel_id' => $this->hotel->id,
                'guest_id' => fdBook($this->hotel, $this->type, [null])->guest_id,
                'reservation_id' => 'RES-NEW',
                'arrival_date' => ($this->today)(1),
                'departure_date' => ($this->today)(3),
                'status' => 'confirmed',
                'adults' => 1,
                'rooms' => [['room_type_id' => $this->type->id, 'room_id' => $this->room->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rooms.0.room_id' => $this->clash]);
    });

    it('refuses the clash when an edit moves the dates onto it', function () {
        $later = fdBook($this->hotel, $this->type, [$this->room->id], ['arrival_date' => ($this->today)(5), 'departure_date' => ($this->today)(6)]);

        $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
            ->putJson("/api/reservation/{$later->id}", ['arrival_date' => ($this->today)(1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rooms' => $this->clash]);
    });

    it('accepts one edit that moves the dates and leaves the clashing room for a free one', function () {
        $later = fdBook($this->hotel, $this->type, [$this->room->id], ['arrival_date' => ($this->today)(5), 'departure_date' => ($this->today)(6)]);
        $line = $later->reservationRooms()->sole();

        $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
            ->putJson("/api/reservation/{$later->id}", [
                'arrival_date' => ($this->today)(1),
                'rooms' => [['id' => $line->id, 'room_id' => $this->rooms[1]->id]],
            ])
            ->assertOk();

        expect($line->fresh()->room_id)->toBe($this->rooms[1]->id);
    });

    it('refuses the clash at check-in with a named room', function () {
        $reservation = fdBook($this->hotel, $this->type, [null]);
        [$stay] = fdStays($reservation);

        fdPost($this, $this->admin, "/api/stays/{$stay->id}/check-in", ['room_id' => $this->room->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["stays.{$stay->id}.room_id" => $this->clash]);
    });

    it('refuses the clash through the Admin AI', function () {
        $reservation = fdBook($this->hotel, $this->type, [null], ['reservation_id' => 'RES-AI']);

        $result = aatCall(aatTool($this->admin, 'AssignRoomsTool'), ['code' => 'RES-AI', 'assignments' => [['room_number' => $this->room->room_number]]]);

        expect($result)->toContain($this->clash)
            ->and($reservation->reservationRooms()->sole()->room_id)->toBeNull();
    });

    it('takes a room given away meanwhile off a reservation brought back from cancelled', function () {
        $cancelled = fdBook($this->hotel, $this->type, [$this->rooms[1]->id], ['arrival_date' => ($this->today)(1), 'departure_date' => ($this->today)(2)]);
        $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
            ->putJson("/api/reservation/{$cancelled->id}", ['status' => 'cancelled'])->assertOk();
        fdBook($this->hotel, $this->type, [$this->rooms[1]->id], ['arrival_date' => ($this->today)(1), 'departure_date' => ($this->today)(2), 'reservation_id' => 'RES-TAKER']);

        $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
            ->putJson("/api/reservation/{$cancelled->id}", ['status' => 'confirmed'])
            ->assertOk();

        expect($response->json('message'))->toContain("Room {$this->rooms[1]->room_number} is booked for RES-TAKER on overlapping nights. It was taken off this booking")
            ->and($cancelled->reservationRooms()->sole()->room_id)->toBeNull();
    });

    it('keeps the room on a no-show undo when nobody took it', function () {
        $reservation = fdBook($this->hotel, $this->type, [$this->rooms[1]->id]);
        fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/no-show")->assertOk();
        fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/undo-no-show")
            ->assertOk()
            ->assertJsonPath('body.warnings', []);

        expect($reservation->reservationRooms()->sole()->room_id)->toBe($this->rooms[1]->id);
    });

    it('takes a room someone else took off a no-show undo, with a warning', function () {
        $reservation = fdBook($this->hotel, $this->type, [$this->rooms[1]->id]);
        fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/no-show")->assertOk();
        fdBook($this->hotel, $this->type, [$this->rooms[1]->id], ['reservation_id' => 'RES-TAKER']);

        $warnings = fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/undo-no-show")->assertOk()->json('body.warnings');

        expect($warnings[0])->toContain('RES-TAKER')
            ->and($reservation->reservationRooms()->sole()->room_id)->toBeNull();
    });
});

it('frees the room for its remaining nights when the stay departs', function () {
    $held = ($this->held)();
    ocStay($held, StayStatus::IN_HOUSE)->update(['status' => StayStatus::DEPARTED]);

    $next = ($this->held)(['arrival_date' => '2026-11-03', 'departure_date' => '2026-11-04']);

    expect(ocHeldNights($next))->toBe('[2026-11-03,2026-11-04)');
});
