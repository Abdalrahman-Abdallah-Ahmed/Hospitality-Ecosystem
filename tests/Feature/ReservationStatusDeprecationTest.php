<?php

use App\Ai\Tools\CreateReservationTool;
use App\Enums\Permission;
use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/*
| Checking guests in or out by setting the reservation's status is
| deprecated (FR-020, FR-020a), and a guest in the house cannot be left
| behind by cancelling, deleting or dropping their room (FR-021). The import
| still records history as given (FR-022).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function putReservation($test, $user, Reservation $reservation, array $payload)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", $payload);
}

it('checks the reservation in through the real check-in, marked deprecated', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);

    putReservation($this, $hotel->owner, $reservation, ['status' => 'checked_in'])
        ->assertOk()
        ->assertHeader('Deprecation', 'true')
        ->assertHeader('Link', "</api/reservation/{$reservation->id}/check-in>; rel=\"successor-version\"")
        ->assertJsonPath('body.status', 'checked_in')
        ->assertJson(fn ($json) => $json->where('message', fn ($message) => str_contains($message, 'deprecated'))->etc());

    expect(collect(fdStays($reservation))->pluck('status')->unique()->all())->toBe([StayStatus::IN_HOUSE]);
});

it('applies the check-in rules on the deprecated path: no room, no check-in', function () {
    [$hotel, $type] = fdHotel();
    $reservation = fdBook($hotel, $type, [null]);

    putReservation($this, $hotel->owner, $reservation, ['status' => 'checked_in'])->assertUnprocessable();

    expect($reservation->fresh()->status->value)->toBe('confirmed');
});

it('checks the reservation out on the deprecated path, with cleaning tasks', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);
    putReservation($this, $hotel->owner, $reservation, ['status' => 'checked_in'])->assertOk();

    putReservation($this, $hotel->owner, $reservation, ['status' => 'checked_out'])
        ->assertOk()
        ->assertHeader('Link', "</api/reservation/{$reservation->id}/check-out>; rel=\"successor-version\"");

    expect(fdStays($reservation)[0]->status)->toBe(StayStatus::DEPARTED)
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('needs the stays permission on top of reservations.update', function (string $status, Permission $missing) {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);

    if ($status === 'checked_out') {
        putReservation($this, $hotel->owner, $reservation, ['status' => 'checked_in'])->assertOk();
    }

    $granted = array_values(array_filter(
        [Permission::RESERVATIONS_UPDATE, Permission::STAYS_CHECK_IN, Permission::STAYS_CHECK_OUT],
        fn (Permission $permission) => $permission !== $missing,
    ));

    putReservation($this, fdEmployee($hotel, $granted), $reservation, ['status' => $status])->assertForbidden();
})->with([
    'check-in' => ['checked_in', Permission::STAYS_CHECK_IN],
    'check-out' => ['checked_out', Permission::STAYS_CHECK_OUT],
]);

it('creates a checked-in reservation by creating it confirmed and checking it in', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(6), 'channel' => 'booking_com']);
    $payload = [
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-WALKIN',
        'arrival_date' => now()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
        'rooms' => [['room_type_id' => $type->id, 'room_id' => $a->id]],
    ];

    fdPost($this, $hotel->owner, '/api/reservation', [...$payload, 'status' => 'checked_in'])
        ->assertCreated()
        ->assertHeader('Deprecation', 'true')
        ->assertJsonPath('body.status', 'checked_in');

    fdPost($this, $hotel->owner, '/api/reservation', [...$payload, 'reservation_id' => 'RES-PAST', 'status' => 'checked_out'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'Only the reservation import can record a checked-out reservation.']);
});

it('refuses to cancel a reservation with a guest in the house', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/check-in")->assertOk();

    putReservation($this, $hotel->owner, $reservation, ['status' => 'cancelled'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'Check out the in-house rooms first.']);
});

it('refuses to drop a room whose guest is in', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    [$first, $second] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/check-in")->assertOk();

    putReservation($this, $hotel->owner, $reservation, ['rooms' => [['id' => $second->reservation_room_id]]])
        ->assertUnprocessable();

    expect($first->fresh()->status)->toBe(StayStatus::IN_HOUSE);
});

it('refuses to delete a reservation with a guest in the house, and deletes its stays otherwise', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $inHouse = fdBook($hotel, $type, [$a->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$inHouse->id}/check-in")->assertOk();
    $waiting = fdBook($hotel, $type, [$b->id]);
    $delete = fn (Reservation $reservation) => $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->deleteJson("/api/reservation/{$reservation->id}");

    $delete($inHouse)->assertUnprocessable()->assertJsonValidationErrors(['reservation' => 'Check out the in-house rooms first.']);
    $delete($waiting)->assertOk();

    expect(Reservation::find($inHouse->id))->not->toBeNull()
        ->and(Stay::where('reservation_id', $waiting->id)->count())->toBe(0)
        ->and(Stay::withTrashed()->where('reservation_id', $waiting->id)->count())->toBe(1);
});

it('moves an in-house guest to another room and leaves the old one dirty', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    putReservation($this, $hotel->owner, $reservation, ['rooms' => [['id' => $stay->reservation_room_id, 'room_id' => $b->id]]])->assertOk();

    expect($stay->fresh()->room_id)->toBe($b->id)
        ->and($b->fresh()->status)->toBe('occupied')
        ->and($a->fresh()->status)->toBe('available')
        ->and($a->fresh()->housekeeping_status->value)->toBe('dirty');
});

it('lets the import record a checked-out stay as history, with no cleaning task', function () {
    [$hotel] = fdHotel();
    $path = tempnam(sys_get_temp_dir(), 'reservations').'.csv';
    file_put_contents($path, "guest_phone,arrival_date,departure_date,room_type,status\n555-0101,2026-01-10,2026-01-12,Deluxe,checked_out");

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => new UploadedFile($path, 'reservations.csv', 'text/csv', null, true)])
        ->assertOk();

    $stay = Stay::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->sole();
    expect($stay->status)->toBe(StayStatus::DEPARTED)
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('does not let the Admin AI create a checked-in reservation', function () {
    [$hotel, $type, [$a]] = fdHotel();

    $answer = (string) (new CreateReservationTool($hotel))->handle(new ToolRequest([
        'guest_phone' => '+201000000000',
        'rooms' => [['room_type' => 'Deluxe']],
        'arrival_date' => now()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
        'status' => 'checked_in',
    ]));

    expect($answer)->toContain('check-in tool')
        ->and(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(0);
});
