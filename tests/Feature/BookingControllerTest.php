<?php

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\User;
use App\Services\ActivityAvailabilityService;
use App\Services\BookingCancellationService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function bookingApi(User $user, string $method, string $uri, array $payload = [])
{
    return test()->withHeaders(wp5Headers())->actingAs($user, 'sanctum')
        ->json($method, $uri, $payload);
}

function employeeOf($hotel): User
{
    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
}

it('lets staff take a booking at the desk', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'scheduled_date' => now()->toDateString(),
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'pax' => 2,
    ])->assertCreated()->assertJsonPath('body.origin', BookingOrigin::STAFF->value);

    $booking = Booking::where('hotel_id', $hotel->id)->first();

    expect($booking->pax)->toBe(2)
        ->and($booking->created_by_user_id)->toBe($admin->id)
        // Priced from the catalogue rather than trusted from the request.
        ->and((float) $booking->expected_value)->toBe(60.0);
});

it('lets an employee take a booking and move it through its lifecycle', function () {
    [, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);
    $seller = employeeOf($hotel);

    $id = bookingApi($seller, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'scheduled_date' => now()->toDateString(),
        'charge_model' => ChargeModel::INCLUDED->value,
    ])->assertCreated()->json('body.id');

    bookingApi($seller, 'POST', "/api/booking/{$id}/status", ['status' => 'confirmed'])->assertOk();

    // The desk is the only place that knows whether the guest turned up.
    bookingApi($seller, 'POST', "/api/booking/{$id}/status", ['status' => 'realised'])
        ->assertOk()
        ->assertJsonPath('body.status', BookingStatus::REALISED->value);

    expect(Booking::withoutGlobalScope('hotel')->find($id)->realised_at)->not->toBeNull();
});

it('credits the recommendation when a staff booking carries one', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'scheduled_date' => now()->toDateString(),
        'recommendation_id' => $recommendation->id,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
    ])->assertCreated()->assertJsonPath('body.origin', BookingOrigin::RECOMMENDATION->value);

    expect(wp5Outcome($recommendation)->outcome->value)->toBe('booked');
});

it('will not let a booking claim recommendation origin without the link', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);

    // 'recommendation' is not an accepted origin value — it is derived.
    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'scheduled_date' => now()->toDateString(),
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'origin' => 'recommendation',
    ])->assertStatus(422)->assertJsonValidationErrors('origin');
});

it('requires a reason to cancel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'cancelled'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", [
        'status' => 'cancelled', 'reason' => 'weather',
    ])->assertOk()->assertJsonPath('body.cancellation_reason', 'weather');
});

it('refuses to reopen a cancelled booking through the endpoint', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();
    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'realised'])->assertStatus(422);
});

it('refuses to move a realised booking back through the endpoint', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'realised'])->assertOk();

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'confirmed'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A realised booking cannot be marked confirmed.');
});

it('rejects a guest from another hotel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();
    [, $otherGuest] = wp5Recommendation($otherHotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $otherGuest->id,
        'item_name' => 'Sunset dive',
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
    ])->assertStatus(422);
});

it('lists only the caller hotel bookings', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();

    wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);
    wp5Booking($otherHotel, ['guest_id' => wp5Recommendation($otherHotel)[1]->id]);

    bookingApi($admin, 'GET', '/api/booking')->assertOk()->assertJsonPath('body.meta.total', 1);
});

it('forbids touching another hotel booking', function () {
    [$admin] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();
    $theirs = wp5Booking($otherHotel, ['guest_id' => wp5Recommendation($otherHotel)[1]->id]);

    bookingApi($admin, 'GET', "/api/booking/{$theirs->id}")->assertStatus(403);
    bookingApi($admin, 'POST', "/api/booking/{$theirs->id}/status", ['status' => 'confirmed'])->assertStatus(403);
});

it('has no PUT or delete route', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    // A booking is corrected with PATCH and cancelled, never replaced or removed.
    bookingApi($admin, 'PUT', "/api/booking/{$booking->id}", ['item_name' => 'x'])->assertStatus(405);
    bookingApi($admin, 'DELETE', "/api/booking/{$booking->id}")->assertStatus(405);
});

/*
| Availability at booking time (SPEC-041, US1). Monday 5 October 2026; the
| activity runs Fridays 17:00-19:00 for 10 people, in season through
| October, closed on Friday the 16th.
*/

function abCruise($hotel, array $attributes = [])
{
    return abActivity($hotel, [
        'operating_hours' => ['friday' => [['start' => '17:00', 'end' => '19:00']]],
        'available_from' => '2026-10-01',
        'available_until' => '2026-10-31',
        'unavailable_periods' => [['start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'reason' => 'Hull repair']],
        'daily_capacity' => 10,
        ...$attributes,
    ]);
}

function abDeskBooking($hotel, $activity, array $payload, ?User $user = null)
{
    return bookingApi($user ?? User::find($hotel->owner_id), 'POST', '/api/booking', [
        'guest_id' => abGuest($hotel)->id,
        'activity_id' => $activity->id,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        ...$payload,
    ]);
}

it('takes a booking that fits and reports the places left', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel);
    abBook($hotel, $cruise, '2026-10-09 17:00', 6);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 4])
        ->assertCreated()
        ->assertJsonPath('body.scheduled_date', '2026-10-09')
        ->assertJsonPath('body.scheduled_time', '17:30');

    expect(app(ActivityAvailabilityService::class)->day($cruise, '2026-10-09')->remaining)->toBe(0);
});

it('refuses each unavailable booking with its reason', function (array $payload, string $reason) {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel);
    abBook($hotel, $cruise, '2026-10-23 17:00', 10);

    abDeskBooking($hotel, $cruise, $payload)
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', $reason)
        ->assertJsonValidationErrors('scheduled_for');

    expect(Booking::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(1);
})->with([
    'fully booked' => [['scheduled_for' => '2026-10-23 17:30', 'pax' => 1], 'fully_booked'],
    'closed weekday' => [['scheduled_for' => '2026-10-13 17:30'], 'closed_weekday'],
    'outside opening hours' => [['scheduled_for' => '2026-10-09 20:00'], 'outside_opening_hours'],
    'out of season' => [['scheduled_for' => '2026-11-06 17:30'], 'out_of_season'],
    'closure period' => [['scheduled_for' => '2026-10-16 17:30'], 'closure_period'],
    'no time on a day with hours' => [['scheduled_date' => '2026-10-09'], 'time_required'],
    'past date' => [['scheduled_for' => '2026-10-02 17:30'], 'past_date'],
]);

it('tells the desk the remaining places and the day windows', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel);
    abBook($hotel, $cruise, '2026-10-09 17:00', 8);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 3])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.remaining', 2)
        ->assertJsonPath('unavailable.capacity', 10)
        ->assertJsonPath('unavailable.overridable', true);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 20:00'])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.windows.0.start', '17:00')
        ->assertJsonPath('unavailable.overridable', false);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-16 17:30'])
        ->assertJsonPath('unavailable.closure_reason', 'Hull repair');
});

it('refuses an activity that is no longer offered', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel, ['is_active' => false]);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30'])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', 'inactive');
});

it('requires a date for a catalogue booking but not for a free-text one', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();

    abDeskBooking($hotel, abCruise($hotel), [])->assertStatus(422)->assertJsonValidationErrors('scheduled_for');

    bookingApi(User::find($hotel->owner_id), 'POST', '/api/booking', [
        'guest_id' => abGuest($hotel)->id,
        'item_name' => 'Private yacht charter',
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'pax' => 40,
    ])->assertCreated();
});

it('reads a time without an offset as the hotel local time', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel('Asia/Dubai');
    $cruise = abCruise($hotel);

    $id = abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30'])->assertCreated()->json('body.id');
    $booking = Booking::withoutGlobalScope('hotel')->find($id);

    expect($booking->scheduled_date->toDateString())->toBe('2026-10-09')
        ->and(substr($booking->scheduled_time, 0, 5))->toBe('17:30')
        ->and($booking->scheduled_for->utc()->toDateTimeString())->toBe('2026-10-09 13:30:00');

    // An explicit offset is an instant: 13:30Z is 17:30 in Dubai.
    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09T13:30:00Z'])
        ->assertCreated()
        ->assertJsonPath('body.scheduled_time', '17:30');
});

it('links a booking to a reservation, from the stay when only the stay is given', function () {
    $this->travelTo('2026-10-05 09:00:00');
    [$hotel, $type, $rooms] = fdHotel(2);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    $other = fdBook($hotel, $type, [$rooms[1]->id]);
    [$stay] = fdStays($reservation);
    $activity = abActivity($hotel);

    abDeskBooking($hotel, $activity, ['scheduled_date' => '2026-10-06', 'guest_id' => $reservation->guest_id, 'stay_id' => $stay->id])
        ->assertCreated()
        ->assertJsonPath('body.reservation_id', $reservation->id);

    abDeskBooking($hotel, $activity, ['scheduled_date' => '2026-10-06', 'stay_id' => $stay->id, 'reservation_id' => $other->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The selected stay does not belong to the selected reservation.');
});

it('rejects a reservation from another hotel', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    [$otherHotel, $type, $rooms] = fdHotel(1);
    $theirs = fdBook($otherHotel, $type, [$rooms[0]->id]);

    abDeskBooking($hotel, abActivity($hotel), ['scheduled_date' => '2026-10-06', 'reservation_id' => $theirs->id])
        ->assertStatus(422);
});

/*
| The booking list and calendar (US6).
*/

it('filters the list by activity, reservation, date range and open request', function () {
    $this->travelTo('2026-10-05 09:00:00');
    [$hotel, $type, $rooms] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    $boat = abActivity($hotel, ['name' => 'Boat']);
    $spa = abActivity($hotel, ['name' => 'Spa']);

    $late = abBook($hotel, $boat, '2026-10-08 15:00', 1);
    $early = abBook($hotel, $boat, '2026-10-08 09:00', 1);
    abBook($hotel, $boat, '2026-10-20', 1);
    abBook($hotel, $spa, '2026-10-08', 1);
    $linked = abBook($hotel, $spa, '2026-10-09', 2, ['reservation_id' => $reservation->id, 'guest_id' => $reservation->guest_id]);
    app(BookingCancellationService::class)->request($linked, Guest::withoutGlobalScope('hotel')->find($linked->guest_id), null);

    $admin = User::find($hotel->owner_id);

    $week = bookingApi($admin, 'GET', "/api/booking?activity_id={$boat->id}&scheduled_from=2026-10-05&scheduled_to=2026-10-11")->assertOk()->json('body.data');

    expect(array_column($week, 'id'))->toBe([$early->id, $late->id])
        ->and($week[0]['cancellation_requested'])->toBeFalse();

    expect(array_column(bookingApi($admin, 'GET', "/api/booking?reservation_id={$reservation->id}")->json('body.data'), 'id'))->toBe([$linked->id])
        ->and(array_column(bookingApi($admin, 'GET', '/api/booking?cancellation_requested=1')->json('body.data'), 'id'))->toBe([$linked->id])
        ->and(bookingApi($admin, 'GET', '/api/booking?cancellation_requested=1')->json('body.data.0.cancellation_requested'))->toBeTrue()
        ->and(count(bookingApi($admin, 'GET', '/api/booking?filter[status]=pending')->json('body.data')))->toBe(5)
        ->and(count(bookingApi($admin, 'GET', "/api/booking?filter[guest_id]={$reservation->guest_id}")->json('body.data')))->toBe(1);

    bookingApi($admin, 'GET', '/api/booking?scheduled_from=2026-10-10&scheduled_to=2026-10-09')->assertStatus(422);
});

it('agrees with the calendar on how many people each day holds', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $boat = abActivity($hotel, ['daily_capacity' => 20]);
    abBook($hotel, $boat, '2026-10-08', 3);
    abBook($hotel, $boat, '2026-10-08', 4);
    app(BookingService::class)->cancel(abBook($hotel, $boat, '2026-10-08', 5), 'changed plans');
    $admin = User::find($hotel->owner_id);

    $listed = collect(bookingApi($admin, 'GET', "/api/booking?activity_id={$boat->id}&scheduled_from=2026-10-08&scheduled_to=2026-10-08")->json('body.data'))
        ->whereIn('status', ['pending', 'confirmed', 'realised'])
        ->sum('pax');

    expect(fdGet($this, $admin, "/api/activity/{$boat->id}/availability?from=2026-10-08")->json('body.days.0.booked'))->toBe($listed)
        ->and($listed)->toBe(7);
});

it('lists a page of bookings with a fixed number of queries', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $boat = abActivity($hotel);
    $admin = User::find($hotel->owner_id);

    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        bookingApi($admin, 'GET', '/api/booking')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    abBook($hotel, $boat, '2026-10-08', 1);
    $few = $count();

    foreach (range(1, 8) as $i) {
        abBook($hotel, $boat, '2026-10-08', 1);
    }

    expect($count())->toBe($few);
});

/*
| Booking past capacity on purpose (US7).
*/

it('lets staff with the permission book past capacity, and audits it', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel, ['daily_capacity' => 4]);
    abBook($hotel, $cruise, '2026-10-09 17:00', 3);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 2])->assertStatus(422);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 2, 'capacity_override' => true], fdEmployee($hotel, [Permission::BOOKINGS_CREATE]))
        ->assertForbidden();

    $id = abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 2, 'capacity_override' => true])
        ->assertCreated()
        ->json('body.id');

    expect(EventLog::where('subject_id', $id)->where('event_type', 'booking.capacity_overridden')->sole()->changes)
        ->toMatchArray(['date' => '2026-10-09', 'capacity' => 4, 'booked' => 3, 'pax' => 2]);
});

it('lets an override take a party bigger than a whole day', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $cruise = abCruise($hotel, ['daily_capacity' => 4]);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 6])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.reason', 'party_exceeds_capacity')
        ->assertJsonPath('unavailable.overridable', true);

    abDeskBooking($hotel, $cruise, ['scheduled_for' => '2026-10-09 17:30', 'pax' => 6, 'capacity_override' => true])->assertCreated();
});

it('never overrides a closure, the season or a closed weekday', function (string $when) {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();

    abDeskBooking($hotel, abCruise($hotel), ['scheduled_for' => $when, 'capacity_override' => true])->assertStatus(422);
})->with(['2026-10-16 17:30', '2026-11-06 17:30', '2026-10-13 17:30']);

it('needs no permission when no override is asked for', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();

    abDeskBooking($hotel, abCruise($hotel), ['scheduled_for' => '2026-10-09 17:30', 'capacity_override' => false], fdEmployee($hotel, [Permission::BOOKINGS_CREATE]))
        ->assertCreated();
});

it('answers ids that are not ids with a validation error', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $admin = User::find($hotel->owner_id);

    bookingApi($admin, 'GET', '/api/booking?activity_id=abc')->assertStatus(422)->assertJsonValidationErrors('activity_id');
    bookingApi($admin, 'GET', '/api/booking?reservation_id=abc')->assertStatus(422)->assertJsonValidationErrors('reservation_id');
    abDeskBooking($hotel, abCruise($hotel), ['scheduled_for' => '2026-10-09 17:30', 'reservation_id' => 'RES-1'])
        ->assertStatus(422)->assertJsonValidationErrors('reservation_id');
});
