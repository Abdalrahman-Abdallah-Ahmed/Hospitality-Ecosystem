<?php

use App\Enums\GuestSignal;
use App\Enums\MeterFeature;
use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveSkipReason;
use App\Enums\ProactiveTrigger;
use App\Enums\TaskStatus;
use App\Jobs\SendProactiveMessageJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\MeterEvent;
use App\Models\PitchDecision;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Services\GuestContactPreferenceService;
use App\Services\Pitching\PitchCoordinator;
use App\Services\Proactive\ProactiveGuardrails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    rapPitchingOn();
    Notification::fake();
    // 07:05 UTC is 10:05 at a hotel in Riyadh (UTC+3).
    $this->travelTo(Carbon::parse('2026-10-10 07:05:00', 'UTC'));
});

/**
 * An in-house guest who wrote two hours ago (window open, not mid-chat), at a
 * hotel with proactive messaging on, with one approved recommendation.
 *
 * @return array{0: Guest, 1: Reservation, 2: Stay, 3: Hotel}
 */
function guardedGuest(array $settings = [], array $guest = []): array
{
    $hotel = rapProactiveOn(rapHotel(), $settings);
    [$guest, $reservation, $stay] = rapInHouseStay($hotel, $guest);
    rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    rapInbound($guest, now()->subHours(2));

    return [$guest, $reservation, $stay, $hotel];
}

function firstMorningRow(Guest $guest): ProactiveMessage
{
    return rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::FIRST_MORNING)->refresh();
}

it('sends a due message inside the window and records it', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest] = guardedGuest();

    rapSweep();

    $row = firstMorningRow($guest);

    expect($row->status)->toBe(ProactiveMessageStatus::SENT)
        ->and($row->locale)->toBe('en')
        ->and($row->body)->toContain('Snorkeling')->not->toContain(':activity')
        ->and($row->sent_at)->not->toBeNull()
        ->and($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toBe($row->body)
        ->and(MeterEvent::where('feature_code', MeterFeature::PROACTIVE_MESSAGES_SENT->value)->count())->toBe(1);
});

it('waits for the end of quiet hours', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest] = guardedGuest(['quiet_hours' => ['start' => '21:00', 'end' => '11:00']]);

    rapSweep();

    $row = firstMorningRow($guest);

    expect($row->status)->toBe(ProactiveMessageStatus::SCHEDULED)
        ->and($row->reason)->toBe(ProactiveSkipReason::QUIET_HOURS)
        ->and($row->due_at->setTimezone('Asia/Riyadh')->toDateTimeString())->toBe('2026-10-10 11:00:00')
        ->and($whatsApp->sent)->toBe([]);

    $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'UTC'));
    rapSweep();

    expect(firstMorningRow($guest)->status)->toBe(ProactiveMessageStatus::SENT);
});

it('drops a message that quiet hours would hold past its time', function () {
    gsFakeWhatsApp();
    [$guest] = guardedGuest(['quiet_hours' => ['start' => '21:00', 'end' => '14:00']]);

    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe(ProactiveSkipReason::QUIET_HOURS);
});

it('sends nothing, on any channel, once the 24-hour window has closed', function () {
    $whatsApp = gsFakeWhatsApp();
    $hotel = rapProactiveOn(rapHotel());
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel), 'approved');
    rapInbound($guest, now()->subHours(30));

    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe(ProactiveSkipReason::OUTSIDE_WINDOW)
        ->and($whatsApp->sent)->toBe([]);
    Notification::assertNothingSent();
});

it('waits while the guest is mid-conversation', function () {
    gsFakeWhatsApp();
    [$guest] = guardedGuest();
    rapInbound($guest, now()->subMinutes(10));

    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SCHEDULED)->reason->toBe(ProactiveSkipReason::GUEST_ACTIVE);
});

it('keeps to the daily cap, and a reminder goes before an offer', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest, $reservation, $stay, $hotel] = guardedGuest();
    abBook($hotel, rapActivity($hotel, 'Safari'), '2026-10-10 15:00:00', 1, ['guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id, 'status' => 'confirmed']);

    // The offer is due at 10:00, the reminder at 11:00: the reminder wins
    // today, and tomorrow is too late for a first-morning message.
    rapSweep();
    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe(ProactiveSkipReason::DAILY_CAP);

    $this->travel(1)->hours();
    rapSweep();

    $reminder = rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::UPCOMING_ACTIVITY)->refresh();
    expect($reminder->status)->toBe(ProactiveMessageStatus::SENT)
        ->and($whatsApp->sent)->toHaveCount(1);
});

it('stops when the guest opted out', function () {
    gsFakeWhatsApp();
    [$guest] = guardedGuest();
    app(GuestContactPreferenceService::class)->optOut($guest, 'staff');

    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe(ProactiveSkipReason::OPTED_OUT);
});

it('applies every pitch rule to a proactive offer', function (string $case, ProactiveSkipReason $reason) {
    gsFakeWhatsApp();
    [$guest, $reservation, $stay] = guardedGuest();

    match ($case) {
        'departing today' => $stay->forceFill(['planned_departure_date' => now('Asia/Riyadh')->toDateString()])->saveQuietly(),
        'escalated' => tap(Task::make(['hotel_id' => $guest->hotel_id, 'guest_id' => $guest->id, 'title' => 'Escalation', 'created_by' => 'guest', 'status' => TaskStatus::PENDING]), fn ($task) => $task->forceFill(['guest_signal' => GuestSignal::ESCALATION])->save()),
        'open complaint' => tap(Task::make(['hotel_id' => $guest->hotel_id, 'guest_id' => $guest->id, 'title' => 'AC broken', 'created_by' => 'guest', 'status' => TaskStatus::PENDING]), fn ($task) => $task->forceFill(['guest_signal' => GuestSignal::SERVICE_REQUEST])->save()),
        'pitching off' => config(['pitching.enabled' => false]),
        'nothing approved' => Recommendation::where('reservation_id', $reservation->id)->update(['status' => 'pending_approval']),
    };

    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe($reason);
})->with([
    'departing today' => ['departing today', ProactiveSkipReason::DEPARTING],
    'escalated' => ['escalated', ProactiveSkipReason::ESCALATED],
    'open complaint' => ['open complaint', ProactiveSkipReason::OPEN_COMPLAINT],
    'pitching off' => ['pitching off', ProactiveSkipReason::PITCHING_DISABLED],
    'nothing approved' => ['nothing approved', ProactiveSkipReason::NO_CANDIDATE],
]);

it('stops a reminder for a booking cancelled meanwhile, and anything past its time', function () {
    gsFakeWhatsApp();
    [$guest, $reservation, $stay, $hotel] = guardedGuest(['triggers' => ['first_morning' => false]]);
    $booking = abBook($hotel, rapActivity($hotel, 'Safari'), '2026-10-10 15:00:00', 1, ['guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id, 'status' => 'confirmed']);
    $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
    rapSweep();
    $booking->forceFill(['status' => 'cancelled'])->saveQuietly();
    $this->travelTo(Carbon::parse('2026-10-10 08:05:00', 'UTC'));

    rapSweep();

    expect(rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::UPCOMING_ACTIVITY)->refresh())
        ->status->toBe(ProactiveMessageStatus::SKIPPED)
        ->reason->toBe(ProactiveSkipReason::BOOKING_NOT_CONFIRMED);
});

it('retries a failed send once, then records the failure', function () {
    $whatsApp = gsFakeWhatsApp(failures: 5);
    [$guest] = guardedGuest();

    rapSweep();
    $row = firstMorningRow($guest);
    expect($row)->status->toBe(ProactiveMessageStatus::SCHEDULED)->attempts->toBe(1)->reason->toBe(ProactiveSkipReason::SEND_FAILED);

    $this->travel(6)->minutes();
    rapSweep();

    expect(firstMorningRow($guest))->status->toBe(ProactiveMessageStatus::FAILED)->attempts->toBe(2)
        ->and($whatsApp->sent)->toBe([])
        ->and(Recommendation::whereNotNull('pitch_decision_id')->count())->toBe(0);
});

it('sends once when two workers race for the same message', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest, $reservation, $stay, $hotel] = guardedGuest(['triggers' => ['first_morning' => false, 'mid_stay' => false, 'recommendation_approved' => false]]);
    $row = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id,
        'trigger' => 'first_morning', 'event_key' => 'first_morning:race', 'status' => 'scheduled',
        'due_at' => now()->subMinute(), 'valid_until' => now()->addHour(),
    ]))->save();
    rapProactiveOn($hotel);

    app()->call([new SendProactiveMessageJob($row->id), 'handle']);
    app()->call([new SendProactiveMessageJob($row->id), 'handle']);

    expect($whatsApp->sent)->toHaveCount(1);
});

it('stores a deferral in app time for a hotel west of UTC', function () {
    gsFakeWhatsApp();
    // 13:05 UTC is 09:05 in New York (UTC-4 in October): inside quiet hours that end at 10:00.
    $this->travelTo(Carbon::parse('2026-10-10 13:05:00', 'UTC'));
    $hotel = rapProactiveOn(rapHotel('America/New_York'), ['quiet_hours' => ['start' => '21:00', 'end' => '10:00'], 'milestone_time' => '09:00']);
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel), 'approved');
    rapInbound($guest, now()->subHours(2));

    rapSweep();

    $row = firstMorningRow($guest);

    expect($row->reason)->toBe(ProactiveSkipReason::QUIET_HOURS)
        ->and($row->due_at->toDateTimeString())->toBe('2026-10-10 14:00:00');
});

it('handles one message per guest at a time: a second waits a minute', function () {
    $whatsApp = gsFakeWhatsApp();
    [$guest, $reservation, $stay, $hotel] = guardedGuest();
    $row = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id,
        'trigger' => 'first_morning', 'event_key' => 'first_morning:locked', 'status' => 'scheduled',
        'due_at' => now()->subMinute(), 'valid_until' => now()->addHour(),
    ]))->save();
    $held = Cache::lock('proactive-guest:'.$guest->id, 60);
    $held->get();

    app()->call([new SendProactiveMessageJob($row->id), 'handle']);

    expect($row->fresh())->status->toBe(ProactiveMessageStatus::SCHEDULED)
        ->and($row->fresh()->due_at->greaterThan(now()))->toBeTrue()
        ->and($whatsApp->sent)->toBe([]);

    $held->release();
});

it('keeps a message the guest received as sent when the bookkeeping after it fails', function () {
    gsFakeWhatsApp();
    [$guest] = guardedGuest();
    $this->mock(PitchCoordinator::class, fn ($mock) => $mock->shouldReceive('markPitched')->andThrow(new RuntimeException('outcome guard')));

    rapSweep();

    expect(firstMorningRow($guest)->status)->toBe(ProactiveMessageStatus::SENT);
});

it('refuses to stage a proactive pitch when a guest turn used the budget first', function () {
    $whatsApp = gsFakeWhatsApp();
    config(['pitching.max_unsolicited_per_stay' => 1]);
    [$guest, $reservation, $stay, $hotel] = guardedGuest(['triggers' => ['first_morning' => false, 'mid_stay' => false, 'recommendation_approved' => false]]);
    rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'approved', ['priority' => 5]);
    $row = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id,
        'trigger' => 'first_morning', 'event_key' => 'first_morning:race-turn', 'status' => 'scheduled',
        'due_at' => now()->subMinute(), 'valid_until' => now()->addHour(),
    ]))->save();
    rapProactiveOn($hotel);

    // The guardrails pass, then a guest turn stages a pitch before this
    // message stages its own.
    $guardrails = app(ProactiveGuardrails::class);
    $this->mock(ProactiveGuardrails::class, function ($mock) use ($guardrails, $guest, $reservation) {
        $mock->shouldReceive('check')->andReturnUsing(function ($message, $now) use ($guardrails, $guest, $reservation) {
            $result = $guardrails->check($message, $now);
            rapPitch($guest, $reservation);

            return $result;
        });
    });

    app()->call([new SendProactiveMessageJob($row->id), 'handle']);

    expect($row->fresh())->status->toBe(ProactiveMessageStatus::SKIPPED)->reason->toBe(ProactiveSkipReason::PITCH_CAP)
        ->and(PitchDecision::whereNotNull('proactive_message_id')->count())->toBe(0);
});
