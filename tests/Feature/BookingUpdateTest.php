<?php

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityAvailabilityService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Monday 5 October 2026. The boat runs Fridays 17:00-19:00 for 10 people.
beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    $this->travelTo('2026-10-05 09:00:00');
});

function abBoat($hotel, array $attributes = [])
{
    return abActivity($hotel, [
        'name' => 'Sunset boat',
        'operating_hours' => ['friday' => [['start' => '17:00', 'end' => '19:00']]],
        'daily_capacity' => 10,
        ...$attributes,
    ]);
}

function abEdit($test, $hotel, Booking $booking, array $payload, ?User $user = null)
{
    return abPatch($test, $user ?? User::find($hotel->owner_id), "/api/booking/{$booking->id}", $payload);
}

it('confirms a pending booking and records when', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    fdPost($this, User::find($hotel->owner_id), "/api/booking/{$booking->id}/status", ['status' => 'confirmed'])->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED)
        ->and($booking->fresh()->confirmed_at)->not->toBeNull();
});

it('lets a booking grow into the places left, its own places included', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    abBook($hotel, $boat, '2026-10-09 17:00', 7);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['pax' => 3])->assertOk()->assertJsonPath('body.pax', 3);

    expect(app(ActivityAvailabilityService::class)->day($boat, '2026-10-09')->remaining)->toBe(0);

    abEdit($this, $hotel, $booking, ['pax' => 4])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', 'fully_booked');

    expect($booking->fresh()->pax)->toBe(3);
});

it('refuses a move to a day the activity does not run', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['scheduled_for' => '2026-10-13 17:30'])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', 'closed_weekday');

    abEdit($this, $hotel, $booking, ['scheduled_for' => '2026-10-16 18:00'])
        ->assertOk()
        ->assertJsonPath('body.scheduled_date', '2026-10-16')
        ->assertJsonPath('body.scheduled_time', '18:00');
});

it('changes notes without checking availability', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 2);

    // Full and then closed: a note still goes through.
    abBook($hotel, $boat, '2026-10-09 17:00', 8);
    $boat->update(['unavailable_periods' => [['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'reason' => 'Storm']]]);

    abEdit($this, $hotel, $booking, ['notes' => 'Vegetarian meal'])->assertOk()->assertJsonPath('body.notes', 'Vegetarian meal');
});

it('frees the places when the booking is cancelled', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 4);
    app(BookingService::class)->confirm($booking);

    fdPost($this, User::find($hotel->owner_id), "/api/booking/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'Guest left early'])->assertOk();

    expect(app(ActivityAvailabilityService::class)->day($boat, '2026-10-09')->remaining)->toBe(10);
});

it('refuses to edit a booking that is over', function (string $status) {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);
    $service = app(BookingService::class);

    match ($status) {
        'cancelled' => $service->cancel($booking, 'changed plans'),
        'realised' => $service->realise($booking),
        'no_show' => $service->markNoShow($booking),
    };

    abEdit($this, $hotel, $booking, ['notes' => 'too late'])
        ->assertStatus(422)
        ->assertJsonPath('message', "A {$status} booking can no longer be changed.");
})->with(['cancelled', 'realised', 'no_show']);

it('never changes who the booking is for or how it came about', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['guest_id' => abGuest($hotel)->id, 'status' => 'confirmed', 'origin' => 'staff', 'charge_model' => 'included'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['guest_id', 'status', 'origin', 'charge_model']);
});

it('checks the new activity when the booking moves to another one', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);
    $spa = abActivity($hotel, ['name' => 'Spa', 'daily_capacity' => 1]);

    abEdit($this, $hotel, $booking, ['activity_id' => $spa->id])->assertStatus(422)->assertJsonPath('unavailable.reason', 'party_exceeds_capacity');

    abEdit($this, $hotel, $booking, ['activity_id' => $spa->id, 'pax' => 1])
        ->assertOk()
        ->assertJsonPath('body.item_name', 'Spa');
});

it('lets an old booking with no date be given one before anything else', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 2);
    Booking::withoutGlobalScope('hotel')->whereKey($booking->id)->update(['scheduled_date' => null, 'scheduled_time' => null, 'last_date' => null, 'scheduled_for' => null]);

    abEdit($this, $hotel, $booking, ['notes' => 'x'])->assertStatus(422)->assertJsonValidationErrors('scheduled_for');
    abEdit($this, $hotel, $booking, ['scheduled_for' => '2026-10-09 17:30', 'notes' => 'x'])->assertOk();
});

it('corrects the party of a past booking without the past-date rule', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abActivity($hotel, ['daily_capacity' => 10]), '2026-10-05', 2);
    $this->travelTo('2026-10-07 09:00:00');

    abEdit($this, $hotel, $booking, ['pax' => 3])->assertOk();
    abEdit($this, $hotel, $booking, ['scheduled_date' => '2026-10-06'])->assertStatus(422)->assertJsonPath('unavailable.reason', 'past_date');
});

it('audits what changed and who changed it', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['pax' => 3, 'notes' => 'Birthday'])->assertOk();

    $event = EventLog::where('subject_id', $booking->id)->where('event_type', 'booking.updated')->latest('occurred_at')->first();

    expect($event->actor_id)->toBe($hotel->owner_id)
        ->and($event->changes)->toHaveKey('pax')
        ->and($event->changes)->toHaveKey('notes')
        ->and(Transaction::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('lets an employee edit with bookings.update and forbids one without', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['notes' => 'ok'], fdEmployee($hotel, [Permission::BOOKINGS_UPDATE]))->assertOk();
    abEdit($this, $hotel, $booking, ['notes' => 'no'], fdEmployee($hotel, [Permission::BOOKINGS_VIEW]))->assertForbidden();
});

/*
| Capacity override on an edit (US7).
*/

it('lets staff with the permission push an edit past capacity, and audits it', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    abBook($hotel, $boat, '2026-10-09 17:00', 8);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['pax' => 4, 'capacity_override' => true], fdEmployee($hotel, [Permission::BOOKINGS_UPDATE]))
        ->assertForbidden();

    abEdit($this, $hotel, $booking, ['pax' => 4, 'capacity_override' => true], fdEmployee($hotel, [Permission::BOOKINGS_UPDATE, Permission::BOOKINGS_OVERRIDE_CAPACITY]))
        ->assertOk();

    $event = EventLog::where('subject_id', $booking->id)->where('event_type', 'booking.capacity_overridden')->sole();

    expect($event->changes)->toMatchArray(['date' => '2026-10-09', 'capacity' => 10, 'booked' => 8, 'pax' => 4]);
});

it('never overrides a closed day', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abBoat($hotel), '2026-10-09 17:30', 2);

    abEdit($this, $hotel, $booking, ['scheduled_for' => '2026-10-13 17:30', 'capacity_override' => true])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', 'closed_weekday');
});

it('always lets a party shrink, even once the activity is closed', function () {
    $hotel = avHotel();
    $boat = abBoat($hotel);
    $booking = abBook($hotel, $boat, '2026-10-09 17:30', 4);
    $boat->update(['is_active' => false, 'operating_hours' => ['monday' => [['start' => '09:00', 'end' => '10:00']]]]);

    abEdit($this, $hotel, $booking, ['pax' => 2])->assertOk()->assertJsonPath('body.pax', 2);
    abEdit($this, $hotel, $booking, ['pax' => 3])->assertStatus(422)->assertJsonPath('unavailable.reason', 'inactive');
});

it('keeps the reservation when only the stay is removed', function () {
    [$hotel, $type, $rooms] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    [$stay] = fdStays($reservation);
    $booking = abBook($hotel, abActivity($hotel), '2026-10-06', 1, ['stay_id' => $stay->id, 'reservation_id' => $reservation->id]);

    abEdit($this, $hotel, $booking, ['stay_id' => null])
        ->assertOk()
        ->assertJsonPath('body.stay_id', null)
        ->assertJsonPath('body.reservation_id', $reservation->id);
});

it('refuses a party of zero or fewer on any path', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);

    expect(fn () => abBook($hotel, $activity, '2026-10-09', -3))->toThrow(ValidationException::class)
        ->and(Booking::withoutGlobalScope('hotel')->count())->toBe(0);
});
