<?php

use App\Enums\Permission;
use App\Models\EventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Upgrades (spec 020 US5, FR-015 to FR-017): a line may hold a room of another
| type when the request says so; availability counts it against that type.
*/

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    [$this->hotel, $this->deluxe, $this->deluxeRooms] = fdHotel(2);
    $this->suite = avType($this->hotel, 'Suite');
    $this->suiteRooms = avRooms($this->hotel, $this->suite, 1);
    $this->admin = adminOfHotel($this->hotel);
    $this->nights = ['arrival_date' => now()->addDays(2)->toDateString(), 'departure_date' => now()->addDays(4)->toDateString()];
    $this->reservation = fdBook($this->hotel, $this->deluxe, [null], $this->nights);
    $this->line = $this->reservation->reservationRooms()->sole();
});

function ugPut($test, $user, $reservation, array $assignments, array $extra = [])
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}/rooms", ['assignments' => $assignments, ...$extra]);
}

/**
 * Booked units per type name on the first night of the stay.
 *
 * @return array<string, int>
 */
function ugBooked($test, $admin, array $nights): array
{
    $response = fdGet($test, $admin, "/api/availability?arrival_date={$nights['arrival_date']}&departure_date={$nights['departure_date']}")->assertOk();

    return collect($response->json('body.room_types'))
        ->mapWithKeys(fn (array $row) => [$row['room_type']['name'] => $row['nights'][0]['booked']])
        ->all();
}

it('needs the request to say it is an upgrade', function () {
    $suiteRoom = $this->suiteRooms[0];

    ugPut($this, $this->admin, $this->reservation, [['line_id' => $this->line->id, 'room_id' => $suiteRoom->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => "Room {$suiteRoom->room_number} is not of the line's room type; send upgrade to place it anyway."]);
});

it('upgrades: keeps the booked type, shows the upgrade, audits both types, moves the unit', function () {
    expect(ugBooked($this, $this->admin, $this->nights))->toBe(['Deluxe' => 1, 'Suite' => 0]);

    ugPut($this, $this->admin, $this->reservation, [['line_id' => $this->line->id, 'room_id' => $this->suiteRooms[0]->id, 'upgrade' => true]])
        ->assertOk()
        ->assertJsonPath('body.reservation.rooms.0.room_type_id', $this->deluxe->id)
        ->assertJsonPath('body.reservation.rooms.0.is_upgrade', true);

    $event = EventLog::where('event_type', 'reservation_room.room_upgraded')->where('subject_id', $this->line->id)->sole();
    expect($event->changes)->toMatchArray(['booked_type' => 'Deluxe', 'room_type' => 'Suite'])
        ->and(ugBooked($this, $this->admin, $this->nights))->toBe(['Deluxe' => 0, 'Suite' => 1]);
});

it('names the held room\'s own type on the reservation show and list responses', function () {
    ugPut($this, $this->admin, $this->reservation, [['line_id' => $this->line->id, 'room_id' => $this->suiteRooms[0]->id, 'upgrade' => true]])
        ->assertOk();

    fdGet($this, $this->admin, "/api/reservation/{$this->reservation->id}")
        ->assertOk()
        ->assertJsonPath('body.rooms.0.is_upgrade', true)
        ->assertJsonPath('body.rooms.0.room_type.name', 'Deluxe')
        ->assertJsonPath('body.rooms.0.room.room_type.name', 'Suite');

    fdGet($this, $this->admin, '/api/reservation')
        ->assertOk()
        ->assertJsonPath('body.data.0.rooms.0.room.room_type.name', 'Suite');
});

it('refuses an upgrade into a full type unless overbooking is allowed and asked for', function () {
    fdBook($this->hotel, $this->suite, [null], $this->nights);
    $assignment = [['line_id' => $this->line->id, 'room_id' => $this->suiteRooms[0]->id, 'upgrade' => true]];

    ugPut($this, $this->admin, $this->reservation, $assignment)->assertUnprocessable();
    ugPut($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_ASSIGN_ROOM]), $this->reservation, $assignment, ['overbook_override' => true])->assertForbidden();

    ugPut($this, $this->admin, $this->reservation, $assignment, ['overbook_override' => true])->assertOk();
    expect(EventLog::where('event_type', 'reservation.overbooking_overridden')->where('subject_id', $this->reservation->id)->exists())->toBeTrue();
});

it('drops the upgrade when the line goes back to its type or loses its room', function (bool $unassign) {
    ugPut($this, $this->admin, $this->reservation, [['line_id' => $this->line->id, 'room_id' => $this->suiteRooms[0]->id, 'upgrade' => true]])->assertOk();

    ugPut($this, $this->admin, $this->reservation, [['line_id' => $this->line->id, 'room_id' => $unassign ? null : $this->deluxeRooms[0]->id]])
        ->assertOk()
        ->assertJsonPath('body.reservation.rooms.0.is_upgrade', false);

    expect(ugBooked($this, $this->admin, $this->nights))->toBe(['Deluxe' => 1, 'Suite' => 0]);
})->with(['back to its type' => false, 'unassigned' => true]);

it('upgrades a guest in the house through the room move', function () {
    $reservation = fdBook($this->hotel, $this->deluxe, [$this->deluxeRooms[1]->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $this->admin, "/api/stays/{$stay->id}/check-in")->assertOk();

    fdPost($this, $this->admin, "/api/stays/{$stay->id}/move-room", ['room_id' => $this->suiteRooms[0]->id, 'reason' => 'Complimentary', 'upgrade' => true])
        ->assertOk();

    expect($stay->fresh()->room_id)->toBe($this->suiteRooms[0]->id)
        ->and(EventLog::where('event_type', 'reservation_room.room_upgraded')->exists())->toBeTrue();
});

it('keeps the general reservation edit strict about room types', function () {
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
        ->putJson("/api/reservation/{$this->reservation->id}", ['rooms' => [['id' => $this->line->id, 'room_id' => $this->suiteRooms[0]->id, 'upgrade' => true]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rooms.0.room_id']);
});
