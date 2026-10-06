<?php

use App\Ai\Tools\CreateBookingTool;
use App\Ai\Tools\RequestBookingCancellationTool;
use App\Enums\ActorKind;
use App\Enums\BookingStatus;
use App\Enums\GuestSignal;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Notifications\BookingCancellationRequestedNotification;
use App\Services\BookingService;
use App\Services\StayLifecycleService;
use App\Support\Audit\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

// Monday 5 October 2026. The guest's reservation runs Monday to Friday the 9th.
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    Notification::fake();
});

/**
 * A guest with a reservation arriving today and leaving on Friday, and a
 * daily kayak tour for 4 people that runs 09:00-12:00.
 *
 * @return array{0: Hotel, 1: Reservation, 2: Guest, 3: Activity}
 */
function abConciergeGuest(): array
{
    [$hotel, $type, $rooms] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id], ['departure_date' => '2026-10-09']);
    $guest = Guest::withoutGlobalScope('hotel')->find($reservation->guest_id);
    $activity = abActivity($hotel, [
        'name' => 'Kayak tour',
        'operating_hours' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($day) => [$day => [['start' => '09:00', 'end' => '12:00']]])->all(),
        'daily_capacity' => 4,
    ]);

    return [$hotel, $reservation, $guest, $activity];
}

/**
 * Runs the Concierge's booking tool as the AI agent, like the WhatsApp job.
 */
function abConciergeBook($hotel, $guest, ?Reservation $reservation, array $args): string
{
    return EventLogger::asAiAgent(fn () => (string) (new CreateBookingTool($hotel, $guest, $reservation))->handle(new Request($args)));
}

function abConciergeCancel($hotel, $guest, ?Reservation $reservation, array $args): string
{
    return EventLogger::asAiAgent(fn () => (string) (new RequestBookingCancellationTool($hotel, $guest, $reservation))->handle(new Request($args)));
}

function abGuestBookings($guest)
{
    return Booking::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->get();
}

it('books an open date for the guest, linked to their reservation', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();

    $result = abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00', 'pax' => 2]);
    $booking = abGuestBookings($guest)->sole();

    expect($result)->toContain($booking->reference)
        ->and($booking->status)->toBe(BookingStatus::PENDING)
        ->and($booking->reservation_id)->toBe($reservation->id)
        ->and($booking->stay_id)->toBeNull()
        ->and($booking->scheduled_date->toDateString())->toBe('2026-10-07')
        ->and(EventLog::where('subject_id', $booking->id)->where('event_type', 'booking.created')->value('actor_kind'))
        ->toBe(ActorKind::AI_AGENT);
});

it('links the stay once the guest has checked in', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    [$stay] = fdStays($reservation);
    app(StayLifecycleService::class)->checkIn($stay);

    abConciergeBook($hotel, $guest, $reservation->fresh(), ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']);

    expect(abGuestBookings($guest)->sole()->stay_id)->toBe($stay->id);
});

it('refuses a full date and offers the nearest dates during the stay', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    abBook($hotel, $activity, '2026-10-07 09:00', 4);
    abBook($hotel, $activity, '2026-10-06 09:00', 4);

    $result = abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00', 'pax' => 2]);

    expect($result)->toContain('fully booked')
        ->and($result)->toContain('No booking was made')
        ->and($result)->toContain('Nearest available dates: 2026-10-08, 2026-10-05, 2026-10-09')
        ->and(abGuestBookings($guest))->toHaveCount(0);
});

it('says so when nothing else is free before the guest leaves', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();

    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
        abBook($hotel, $activity, "{$date} 09:00", 4);
    }

    expect(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']))
        ->toContain('No other dates are available');
});

it('refuses a date after the guest leaves or before today', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();

    expect(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-10 10:00']))
        ->toContain('outside the guest\'s stay')
        ->toContain('outside_reservation')
        ->and(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-04 10:00']))
        ->toContain('outside the guest\'s stay')
        ->and(abGuestBookings($guest))->toHaveCount(0);
});

it('asks for a date before booking a catalogue activity', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();

    expect(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id]))->toContain('which date')
        ->and(abGuestBookings($guest))->toHaveCount(0);
});

it('returns the same booking when a message is retried', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    $args = ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00', 'pax' => 2];

    $first = abConciergeBook($hotel, $guest, $reservation, $args);
    $this->travelTo('2026-10-05 09:05:00');
    $second = abConciergeBook($hotel, $guest, $reservation, $args);

    $booking = abGuestBookings($guest)->sole();

    expect($first)->toContain($booking->reference)
        ->and($second)->toContain($booking->reference);

    // Ten minutes later it is a new request, not a retry.
    $this->travelTo('2026-10-05 09:20:00');
    abConciergeBook($hotel, $guest, $reservation, $args);

    expect(abGuestBookings($guest))->toHaveCount(2);
});

it('never books another hotel activity', function () {
    [$hotel, $reservation, $guest] = abConciergeGuest();
    $theirs = abActivity(avHotel());

    abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $theirs->id, 'scheduled_for' => '2026-10-07 10:00']);

    expect(abGuestBookings($guest))->toHaveCount(0);
});

it('makes no booking for a guest without a reservation', function () {
    [$hotel, , $guest, $activity] = abConciergeGuest();

    expect(abConciergeBook($hotel, $guest, null, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']))
        ->toContain('only book activities for a guest with a reservation')
        ->and(abGuestBookings($guest))->toHaveCount(0);
});

it('cannot override capacity, whatever it sends', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    abBook($hotel, $activity, '2026-10-07 09:00', 4);

    abConciergeBook($hotel, $guest, $reservation, [
        'activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00', 'capacity_override' => true,
    ]);

    expect(abGuestBookings($guest))->toHaveCount(0);
});

/*
| Cancellation requests (SPEC-043, US5): the Concierge only ever asks.
*/

it('passes a cancellation request to staff without touching the booking', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']);
    $booking = abGuestBookings($guest)->sole();

    $result = abConciergeCancel($hotel, $guest, $reservation, ['booking_reference' => strtolower($booking->reference), 'reason' => 'Flight changed']);
    $request = Task::withoutGlobalScope('hotel')->where('booking_id', $booking->id)->sole();

    expect($result)->toContain('passed to the team')
        ->and($booking->fresh()->status)->toBe(BookingStatus::PENDING)
        ->and($request->guest_signal)->toBe(GuestSignal::CANCELLATION_REQUEST)
        ->and($request->description)->toBe('Flight changed')
        ->and($request->assigned_to_team_id)->toBeNull()
        ->and($request->reservation_id)->toBe($reservation->id);

    Notification::assertSentTo($hotel->owner()->first(), BookingCancellationRequestedNotification::class);
});

it('does not open a second request when the guest asks again', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']);
    $booking = abGuestBookings($guest)->sole();

    abConciergeCancel($hotel, $guest, $reservation, ['booking_reference' => $booking->reference]);
    $again = abConciergeCancel($hotel, $guest, $reservation, ['booking_reference' => $booking->reference]);

    expect($again)->toContain('already with the team')
        ->and(Task::withoutGlobalScope('hotel')->where('booking_id', $booking->id)->count())->toBe(1);

    Notification::assertSentTimes(BookingCancellationRequestedNotification::class, 1);
});

it('finds nothing for another guest booking or a finished one', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    $someoneElse = abBook($hotel, $activity, '2026-10-07 09:00', 1);
    abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00']);
    $mine = abGuestBookings($guest)->sole();
    app(BookingService::class)->cancel($mine, 'changed plans');

    expect(abConciergeCancel($hotel, $guest, $reservation, ['booking_reference' => $someoneElse->reference]))->toContain('could not find')
        ->and(abConciergeCancel($hotel, $guest, $reservation, ['booking_reference' => $mine->reference]))->toContain('already cancelled')
        ->and(Task::withoutGlobalScope('hotel')->whereNotNull('booking_id')->count())->toBe(0)
        ->and($someoneElse->fresh()->status)->toBe(BookingStatus::PENDING);
});

it('treats a reference sent as the booking id as not found', function () {
    [$hotel, $reservation, $guest] = abConciergeGuest();

    expect(abConciergeCancel($hotel, $guest, $reservation, ['booking_id' => 'DCB-4K2P']))->toContain('could not find');
});

it('asks for a time when only a date is given for an activity with hours', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();

    expect(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07']))
        ->toContain('needs a start time')
        ->and(abGuestBookings($guest))->toHaveCount(0);

    // An activity with no hours takes the date alone, with no time.
    $allDay = abActivity($hotel, ['name' => 'Beach day']);
    abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $allDay->id, 'scheduled_for' => '2026-10-07']);

    expect(abGuestBookings($guest)->sole()->scheduled_time)->toBeNull();
});

it('never books a negative party', function () {
    [$hotel, $reservation, $guest, $activity] = abConciergeGuest();
    abBook($hotel, $activity, '2026-10-07 09:00', 4);

    expect(abConciergeBook($hotel, $guest, $reservation, ['activity_id' => $activity->id, 'scheduled_for' => '2026-10-07 10:00', 'pax' => -3]))
        ->toContain('at least one person')
        ->and(abGuestBookings($guest))->toHaveCount(0);
});
