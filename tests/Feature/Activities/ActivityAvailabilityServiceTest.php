<?php

use App\Enums\ActivityUnavailableReason;
use App\Enums\BookingStatus;
use App\Exceptions\ActivityUnavailableException;
use App\Services\ActivityAvailabilityService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Monday 5 October 2026, 09:00 UTC. Friday is the 9th.
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
});

/**
 * The reason assertBookable() refuses with, or null when the booking fits.
 */
function abRefusal(callable $check): ?ActivityUnavailableReason
{
    try {
        $check();
    } catch (ActivityUnavailableException $e) {
        return $e->reason;
    }

    return null;
}

function abService(): ActivityAvailabilityService
{
    return app(ActivityAvailabilityService::class);
}

it('refuses with the first rule that fails, in order', function () {
    $hotel = avHotel();
    $fridays = ['friday' => [['start' => '17:00', 'end' => '19:00']]];

    $inactive = abActivity($hotel, ['is_active' => false]);
    $activity = abActivity($hotel, [
        'operating_hours' => $fridays,
        'available_from' => '2026-10-01',
        'available_until' => '2026-10-31',
        'unavailable_periods' => [['start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'reason' => 'Hull repair']],
        'daily_capacity' => 10,
    ]);

    expect(abRefusal(fn () => abService()->assertBookable($inactive, '2026-10-09', '17:30', 1)))->toBe(ActivityUnavailableReason::INACTIVE)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-02', '17:30', 1)))->toBe(ActivityUnavailableReason::PAST_DATE)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-11-06', '17:30', 1)))->toBe(ActivityUnavailableReason::OUT_OF_SEASON)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-16', '17:30', 1)))->toBe(ActivityUnavailableReason::CLOSURE_PERIOD)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-08', '17:30', 1)))->toBe(ActivityUnavailableReason::CLOSED_WEEKDAY)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', null, 1)))->toBe(ActivityUnavailableReason::TIME_REQUIRED)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '20:00', 1)))->toBe(ActivityUnavailableReason::OUTSIDE_OPENING_HOURS)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '17:30', 11)))->toBe(ActivityUnavailableReason::PARTY_EXCEEDS_CAPACITY)
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '17:30', 10)))->toBeNull();
});

it('reports the closure reason and the day windows with a refusal', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, [
        'operating_hours' => ['friday' => [['start' => '17:00', 'end' => '19:00']]],
        'unavailable_periods' => [['start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'reason' => 'Hull repair']],
    ]);

    try {
        abService()->assertBookable($activity, '2026-10-16', '17:30', 1);
        $this->fail('Expected a refusal.');
    } catch (ActivityUnavailableException $e) {
        expect($e->unavailable['closure_reason'])->toBe('Hull repair')
            ->and($e->getMessage())->toContain('Hull repair');
    }

    try {
        abService()->assertBookable($activity, '2026-10-09', '20:00', 1);
        $this->fail('Expected a refusal.');
    } catch (ActivityUnavailableException $e) {
        expect($e->unavailable['windows'])->toBe([['start' => '17:00', 'end' => '19:00']])
            ->and($e->unavailable['overridable'])->toBeFalse();
    }
});

it('treats no opening hours as open all day and an empty weekday as closed', function () {
    $hotel = avHotel();
    $allDay = abActivity($hotel);
    $closedFriday = abActivity($hotel, ['operating_hours' => ['friday' => [], 'saturday' => [['start' => '09:00', 'end' => '12:00']]]]);

    expect(abRefusal(fn () => abService()->assertBookable($allDay, '2026-10-09', null, 3)))->toBeNull()
        ->and(abRefusal(fn () => abService()->assertBookable($allDay, '2026-10-09', '23:30', 3)))->toBeNull()
        ->and(abRefusal(fn () => abService()->assertBookable($closedFriday, '2026-10-09', '10:00', 1)))->toBe(ActivityUnavailableReason::CLOSED_WEEKDAY)
        ->and(abRefusal(fn () => abService()->assertBookable($closedFriday, '2026-10-10', '10:00', 1)))->toBeNull();
});

it('accepts a start at the window opening and refuses one at its end', function () {
    $activity = abActivity(avHotel(), ['operating_hours' => ['friday' => [['start' => '17:00', 'end' => '19:00']]]]);

    expect(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '17:00', 1)))->toBeNull()
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '18:59', 1)))->toBeNull()
        ->and(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', '19:00', 1)))->toBe(ActivityUnavailableReason::OUTSIDE_OPENING_HOURS);
});

it('counts pending, confirmed and realised bookings but not cancelled or no-show ones', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 10]);
    $service = app(BookingService::class);

    abBook($hotel, $activity, '2026-10-09', 2);                                     // pending
    $service->confirm(abBook($hotel, $activity, '2026-10-09', 2));                 // confirmed
    $service->realise(abBook($hotel, $activity, '2026-10-09', 1));                 // realised
    $service->cancel(abBook($hotel, $activity, '2026-10-09', 3), 'plans changed'); // cancelled
    $service->markNoShow(abBook($hotel, $activity, '2026-10-09', 1));              // no-show

    $day = abService()->day($activity, '2026-10-09');

    expect($day->booked)->toBe(5)
        ->and($day->remaining)->toBe(5);
});

it('holds a multi-day activity on every day it covers', function () {
    $hotel = avHotel();
    $trek = abActivity($hotel, ['duration_days' => 3, 'daily_capacity' => 4]);

    $booking = abBook($hotel, $trek, '2026-10-09', 3);

    expect($booking->last_date->toDateString())->toBe('2026-10-11')
        ->and(abService()->day($trek, '2026-10-10')->booked)->toBe(3)
        ->and(abService()->day($trek, '2026-10-12')->booked)->toBe(0)
        // A start on the 11th overlaps the 11th, which has 1 place left.
        ->and(abRefusal(fn () => abService()->assertBookable($trek, '2026-10-11', null, 2)))->toBe(ActivityUnavailableReason::FULLY_BOOKED)
        ->and(abRefusal(fn () => abService()->assertBookable($trek, '2026-10-12', null, 4)))->toBeNull();
});

it('refuses a multi-day activity whose later day is closed', function () {
    $trek = abActivity(avHotel(), [
        'duration_days' => 3,
        'unavailable_periods' => [['start_date' => '2026-10-10', 'end_date' => '2026-10-10', 'reason' => null]],
    ]);

    expect(abRefusal(fn () => abService()->assertBookable($trek, '2026-10-09', null, 1)))->toBe(ActivityUnavailableReason::CLOSURE_PERIOD)
        ->and(abService()->day($trek, '2026-10-09')->open)->toBeFalse();
});

it('never refuses for capacity when the activity has none', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel);

    abBook($hotel, $activity, '2026-10-09', 500);

    expect(abRefusal(fn () => abService()->assertBookable($activity, '2026-10-09', null, 500)))->toBeNull()
        ->and(abService()->day($activity, '2026-10-09')->remaining)->toBeNull();
});

it('uses the hotel time zone for today', function () {
    // 11:00 UTC on Monday is already 01:00 on Tuesday in Kiritimati (UTC+14).
    $this->travelTo('2026-10-05 11:00:00');
    $ahead = abActivity(avHotel('Pacific/Kiritimati'));
    $utc = abActivity(avHotel());

    expect(abService()->today($ahead->hotel))->toBe('2026-10-06')
        ->and(abRefusal(fn () => abService()->assertBookable($ahead, '2026-10-05', null, 1)))->toBe(ActivityUnavailableReason::PAST_DATE)
        ->and(abRefusal(fn () => abService()->assertBookable($utc, '2026-10-05', null, 1)))->toBeNull();
});

it('offers the nearest open dates first, within the bounds given', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, [
        'operating_hours' => [
            'wednesday' => [['start' => '10:00', 'end' => '12:00']],
            'friday' => [['start' => '10:00', 'end' => '12:00']],
            'saturday' => [['start' => '10:00', 'end' => '12:00']],
        ],
        'daily_capacity' => 2,
    ]);
    abBook($hotel, $activity, '2026-10-09 10:00', 2); // Friday full

    $dates = abService()->nearestOpenDates($activity, '2026-10-09', 2, 3, '2026-10-05', '2026-10-19');

    expect($dates)->toBe(['2026-10-10', '2026-10-07', '2026-10-14'])
        ->and(abService()->nearestOpenDates($activity, '2026-10-09', 2, 3, '2026-10-05', '2026-10-08'))->toBe(['2026-10-07'])
        ->and(abService()->nearestOpenDates($activity, '2026-10-09', 3, 3, '2026-10-05', '2026-10-19'))->toBe([]);
});

it('never disagrees with the lookup: N places open means N fit and N+1 does not', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 6, 'duration_days' => 2]);
    abBook($hotel, $activity, '2026-10-09', 2);
    abBook($hotel, $activity, '2026-10-10', 3);

    foreach (abService()->range($activity, '2026-10-06', '2026-10-12') as $day) {
        expect($day->open)->toBeTrue();

        if ($day->remaining > 0) {
            expect(abRefusal(fn () => abService()->assertBookable($activity, $day->date, null, $day->remaining)))->toBeNull();
        }

        // One more than a free day's whole capacity can never fit at all.
        expect(abRefusal(fn () => abService()->assertBookable($activity, $day->date, null, $day->remaining + 1)))
            ->toBe($day->remaining === 6 ? ActivityUnavailableReason::PARTY_EXCEEDS_CAPACITY : ActivityUnavailableReason::FULLY_BOOKED);
    }
});

it('flags a date closed by its rules that still holds bookings, but not a past date', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 10]);
    abBook($hotel, $activity, '2026-10-09', 2);
    abBook($hotel, $activity, '2026-10-05', 2);

    $activity->update(['unavailable_periods' => [['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'reason' => 'Storm']]]);
    $this->travelTo('2026-10-06 09:00:00');

    [$past] = abService()->range($activity->fresh(), '2026-10-05', '2026-10-05');
    $closed = abService()->day($activity->fresh(), '2026-10-09');

    expect($past->past)->toBeTrue()
        ->and($past->open)->toBeTrue()
        ->and($past->hasBookingsWhileClosed())->toBeFalse()
        ->and($closed->open)->toBeFalse()
        ->and($closed->reason)->toBe(ActivityUnavailableReason::CLOSURE_PERIOD)
        ->and($closed->hasBookingsWhileClosed())->toBeTrue();
});

it('marks an open date with no places left as fully booked', function () {
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);
    abBook($hotel, $activity, '2026-10-09', 2);

    $day = abService()->day($activity, '2026-10-09');

    expect($day->open)->toBeTrue()
        ->and($day->reason)->toBe(ActivityUnavailableReason::FULLY_BOOKED)
        ->and($day->remaining)->toBe(0)
        ->and(BookingStatus::holdingCapacity())->toContain(BookingStatus::PENDING);
});
