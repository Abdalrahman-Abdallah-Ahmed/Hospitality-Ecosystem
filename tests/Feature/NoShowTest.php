<?php

use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\Pitching\PitchEligibilityService;
use App\Support\Reservations\ReservationCreator;
use App\Support\Reservations\RoomAssignmentRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/** Another booking of the same type for the same nights; throws when none is free. */
function nsSecondBooking(Hotel $hotel, RoomType $type)
{
    return fdBook($hotel, $type, [null]);
}

// --- Whole reservation (US1) ----------------------------------------------

it('marks a reservation that never arrived as a no-show and releases its rooms', function () {
    Notification::fake();
    Queue::fake();
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);

    expect(fn () => nsSecondBooking($hotel, $type))->toThrow(ValidationException::class);

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show", ['reason' => 'No contact'])
        ->assertOk()
        ->assertJsonPath('message', 'Marked as a no-show.')
        ->assertJsonPath('body.reservation.status', 'no_show')
        ->assertJsonPath('body.stays.0.status', 'no_show');

    $reservation->refresh();
    [$stay] = fdStays($reservation);

    expect($reservation->status)->toBe(ReservationStatus::NO_SHOW)
        ->and($reservation->status_before_no_show)->toBe(ReservationStatus::CONFIRMED)
        ->and($stay->status)->toBe(StayStatus::NO_SHOW)
        ->and(nsSecondBooking($hotel, $type)->exists)->toBeTrue()
        ->and(EventLog::where('subject_id', $reservation->id)->where('event_type', 'reservation.no_show_marked')->value('reason'))->toBe('No contact')
        ->and(Transaction::count())->toBe(0);

    Notification::assertNothingSent();
    Queue::assertNotPushed(ExtractGuestMemoriesJob::class);
});

it('can be marked from the arrival day only', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now()->addDay()->toDateString(),
        'departure_date' => now()->addDays(3)->toDateString(),
    ]);

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stays');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('refuses a whole no-show once any room has checked in, or for a cancelled reservation', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id]);
    [$first] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-in")->assertOk();

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stays');

    $cancelled = fdBook($hotel, $type, [null], ['status' => 'cancelled']);
    fdPost($this, $hotel->owner, "/api/reservation/{$cancelled->id}/no-show")->assertUnprocessable();
});

it('changes nothing when a no-show is marked twice', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk()
        ->assertJsonPath('body.reservation.status', 'no_show');

    expect(EventLog::where('subject_id', $reservation->id)->where('event_type', 'reservation.no_show_marked')->count())->toBe(1);
});

it('keeps a no-show out of the arrivals list, pitching and proactive messages', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();
    [$stay] = fdStays($reservation);

    $arrivals = collect(fdGet($this, $hotel->owner, '/api/stays/arrivals')->assertOk()->json('body.stays'))->pluck('id');
    config(['pitching.allow_pre_arrival' => true]);
    $gates = app(PitchEligibilityService::class)->evaluateCheapGates($reservation->guest, $hotel, $stay->fresh(), now());

    expect($arrivals)->not->toContain($stay->id)
        ->and($gates->passed())->toBeFalse();
});

// --- One room (US2) --------------------------------------------------------

it('marks one room a no-show and lets the rest of the group check out', function () {
    [$hotel, $type, $rooms] = fdHotel(3);
    $reservation = fdBook($hotel, $type, array_map(fn (Room $room) => $room->id, $rooms));
    [$a, $b, $c] = fdStays($reservation);

    fdPost($this, $hotel->owner, "/api/stays/{$a->id}/check-in")->assertOk();
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/check-in")->assertOk();
    fdPost($this, $hotel->owner, "/api/stays/{$c->id}/no-show")->assertOk()
        ->assertJsonPath('body.reservation.status', 'checked_in');

    $arrivals = collect(fdGet($this, $hotel->owner, '/api/stays/arrivals')->json('body.stays'))->pluck('id');
    $lines = collect(fdGet($this, $hotel->owner, "/api/reservation/{$reservation->id}")->assertOk()->json('body.rooms'))->keyBy('stay_id');

    expect($arrivals)->not->toContain($c->id)
        ->and($lines[$c->id]['stay_status'])->toBe('no_show')
        ->and($lines[$a->id]['stay_status'])->toBe('in_house');

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/check-out")->assertOk();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::CHECKED_OUT)
        ->and($c->fresh()->status)->toBe(StayStatus::NO_SHOW);
});

it('makes the reservation a no-show when its last waiting room is marked', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id], ['status' => 'pending']);
    [$a, $b] = fdStays($reservation);

    fdPost($this, $hotel->owner, "/api/stays/{$a->id}/no-show")->assertOk()->assertJsonPath('body.reservation.status', 'pending');
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/no-show")->assertOk()->assertJsonPath('body.reservation.status', 'no_show');

    expect($reservation->fresh()->status_before_no_show)->toBe(ReservationStatus::PENDING);
});

it('refuses a room no-show for a room in the house', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/no-show")->assertUnprocessable()->assertJsonValidationErrors('stays');
});

it('frees a no-show line\'s room for another booking on the same nights', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/no-show")->assertOk();

    $other = nsSecondBooking($hotel, $type);
    $line = ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $other->id)->sole();

    expect(RoomAssignmentRules::reason($line, $room))->toBeNull();
});

// --- Undo (US4) ------------------------------------------------------------

it('undoes a no-show back to the status it had', function (string $status) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id], ['status' => $status]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show", ['reason' => 'Arrived late'])
        ->assertOk()
        ->assertJsonPath('message', 'No-show undone.')
        ->assertJsonPath('body.reservation.status', $status);

    [$stay] = fdStays($reservation);

    expect($reservation->fresh()->status_before_no_show)->toBeNull()
        ->and($stay->status)->toBe(StayStatus::EXPECTED)
        ->and(EventLog::where('subject_id', $reservation->id)->where('event_type', 'reservation.no_show_undone')->value('reason'))->toBe('Arrived late');
})->with(['pending', 'confirmed']);

it('undoes a no-show even after its dates have passed', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();

    $this->travel(10)->days();

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show")->assertOk()
        ->assertJsonPath('body.reservation.status', 'confirmed');
});

it('refuses to undo into rooms sold since, unless an allowed overbook', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();
    nsSecondBooking($hotel, $type);

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show")->assertUnprocessable();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::NO_SHOW);

    $noOverbook = fdEmployee($hotel, [Permission::RESERVATIONS_NO_SHOW]);
    fdPost($this, $noOverbook, "/api/reservation/{$reservation->id}/undo-no-show", ['overbook_override' => true])->assertForbidden();

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show", ['overbook_override' => true])->assertOk();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('undoes one room while the reservation is open, but not once it is checked out', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id]);
    [$a, $b] = fdStays($reservation);

    fdPost($this, $hotel->owner, "/api/stays/{$a->id}/check-in")->assertOk();
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/no-show")->assertOk();
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/undo-no-show")->assertOk();
    expect($b->fresh()->status)->toBe(StayStatus::EXPECTED);

    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/no-show")->assertOk();
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/check-out")->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/undo-no-show")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stays' => 'This reservation is checked out; its no-show rooms are final.']);
});

it('refuses to undo a reservation that is not a no-show', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

// --- Review fixes ----------------------------------------------------------

it('takes a room given away meanwhile off the restored line, with a warning', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();

    $other = fdBook($hotel, $type, [$room->id]);

    $response = fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/undo-no-show", ['overbook_override' => true])->assertOk();

    $line = ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole();
    $otherLine = ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $other->id)->sole();

    expect($line->room_id)->toBeNull()
        ->and($otherLine->room_id)->toBe($room->id)
        ->and($response->json('body.warnings.0'))->toContain("is booked for {$other->reservation_id}");
});

it('refuses to restore a room of a cancelled reservation', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id]);
    [, $b] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/no-show")->assertOk();
    $this->withHeaders(apiHeaders())->actingAs($hotel->owner, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['status' => 'cancelled'])->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/undo-no-show")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stays');

    expect($b->fresh()->status)->toBe(StayStatus::NO_SHOW);
});

it('brings a restored room in step with dates changed while it was a no-show', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id]);
    [, $b] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/no-show")->assertOk();

    $newArrival = now()->addDays(10)->toDateString();
    $this->withHeaders(apiHeaders())->actingAs($hotel->owner, 'sanctum')->putJson("/api/reservation/{$reservation->id}", [
        'arrival_date' => $newArrival,
        'departure_date' => now()->addDays(12)->toDateString(),
    ])->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$b->id}/undo-no-show")->assertOk();

    expect($b->fresh()->status)->toBe(StayStatus::EXPECTED)
        ->and($b->fresh()->planned_arrival_date->toDateString())->toBe($newArrival);
});

it('stops an edit that read the reservation before a no-show was committed', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    $stale = $reservation->fresh();

    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk();

    expect(fn () => ReservationCreator::update($stale, ['arrival_date' => now()->addDays(5)->toDateString()], null))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::NO_SHOW)
        ->and($reservation->fresh()->arrival_date->toDateString())->toBe(now()->toDateString());
});
