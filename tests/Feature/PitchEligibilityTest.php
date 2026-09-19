<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Agents\TurnSignalAgent;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Enums\CandidateExclusion;
use App\Enums\GuestSignal;
use App\Enums\InboundMessageStatus;
use App\Enums\PitchGate;
use App\Enums\PitchResult;
use App\Enums\RecommendationStatus;
use App\Enums\SenderType;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\Pitching\PitchCoordinator;
use App\Services\Pitching\PitchEligibilityService;
use App\Services\WhatsAppMessageService;
use App\Support\Pitching\PitchTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'pitching.enabled' => true,
        'services.whatsapp.phone_number_id' => 'test-phone-number-id',
        'services.whatsapp.access_token' => 'test-access-token',
    ]);
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

    // Saturday 19 September, 15:00 at a hotel in UTC+3.
    $this->travelTo(Carbon::parse('2026-09-19 12:00:00', 'UTC'));
});

function pitchHotel(string $timezone = 'Asia/Riyadh'): Hotel
{
    return Hotel::create([
        'owner_id' => User::factory()->role(UserRole::ADMIN)->create()->id,
        'name' => 'Pitch Hotel',
        'slug' => 'pitch-hotel-'.Str::lower(Str::random(6)),
        'currency' => 'USD',
        'timezone' => $timezone,
    ]);
}

/**
 * A guest in-house from the 17th, leaving on the 22nd: the 19th, 20th and
 * 21st are left.
 *
 * @return array{0: Guest, 1: Reservation, 2: Stay}
 */
function pitchGuest(Hotel $hotel, array $stay = []): array
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '2010'.random_int(1000000, 9999999)]);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-17',
        'departure_date' => $stay['planned_departure_date'] ?? '2026-09-22',
        'status' => 'confirmed',
    ]);

    $stay = Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => $reservation->id,
        'planned_arrival_date' => '2026-09-17',
        'planned_departure_date' => '2026-09-22',
        'checked_in_at' => '2026-09-17 14:00:00',
        'status' => StayStatus::IN_HOUSE,
        'adults' => 2,
        'children' => 1,
        ...$stay,
    ]);

    return [$guest, $reservation, $stay];
}

function pitchActivity(Hotel $hotel, array $attributes = []): Activity
{
    return Activity::create(['hotel_id' => $hotel->id, 'name' => 'Sunset Catamaran', 'price' => 80, ...$attributes]);
}

/** The classifier's answer for this turn. */
function classifierSays(array $overrides = []): void
{
    TurnSignalAgent::fake([[
        'complaint' => false,
        'opening' => 'asks_what_to_do',
        'interest_category_id' => null,
        'evidence_quote' => 'what can we do',
        ...$overrides,
    ]]);
}

function decideTurn(Guest $guest, Hotel $hotel, ?Reservation $reservation, string $message = 'Hi! What can we do this evening?'): PitchTurn
{
    return app(PitchCoordinator::class)->begin($guest, $hotel, $reservation, $message, null);
}

/**
 * @return array{gate: string, passed: bool, detail: ?string}|null
 */
function gateOf(PitchTurn $turn, PitchGate $gate): ?array
{
    return collect($turn->decision->gates)->firstWhere('gate', $gate->value);
}

function exclusionOf(PitchTurn $turn, Activity $activity): ?array
{
    return collect($turn->decision->candidates['excluded'])->firstWhere('activity_id', $activity->id);
}

function guestSignalTask(Guest $guest, GuestSignal $signal, array $attributes = []): Task
{
    $task = Task::make([
        'hotel_id' => $guest->hotel_id,
        'guest_id' => $guest->id,
        'title' => 'Guest task',
        'created_by' => 'guest',
        'status' => TaskStatus::PENDING,
        ...$attributes,
    ]);
    $task->guest_signal = $signal;
    $task->save();

    return $task;
}

// --- cheap gates ------------------------------------------------------------

it('blocks a guest on their departure day in the hotel\'s timezone', function () {
    // 22:00 UTC on the 19th is already the 20th in UTC+3.
    $this->travelTo(Carbon::parse('2026-09-19 22:00:00', 'UTC'));
    classifierSays();

    $local = pitchHotel('Asia/Riyadh');
    [$guest, $reservation] = pitchGuest($local, ['planned_departure_date' => '2026-09-20']);
    $utc = pitchHotel('UTC');
    [$utcGuest, $utcReservation] = pitchGuest($utc, ['planned_departure_date' => '2026-09-20']);

    $departing = decideTurn($guest, $local, $reservation);
    $notYet = decideTurn($utcGuest, $utc, $utcReservation);

    expect(gateOf($departing, PitchGate::DEPARTING)['passed'])->toBeFalse()
        ->and(gateOf($departing, PitchGate::DEPARTING)['detail'])->toContain('today is 2026-09-20')
        ->and(gateOf($notYet, PitchGate::DEPARTING)['passed'])->toBeTrue();
});

it('blocks a guest who has already checked out', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel, ['checked_out_at' => '2026-09-19 08:00:00']);
    TurnSignalAgent::fake()->preventStrayPrompts();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and(gateOf($turn, PitchGate::DEPARTING)['detail'])->toBe('The guest has checked out.');
});

it('blocks a stay that is not in-house', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel, ['status' => StayStatus::EXPECTED]);
    TurnSignalAgent::fake()->preventStrayPrompts();

    expect(gateOf(decideTurn($guest, $hotel, $reservation), PitchGate::NOT_IN_HOUSE)['passed'])->toBeFalse();
});

it('blocks when there is no stay', function () {
    $hotel = pitchHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '201000000001']);
    TurnSignalAgent::fake()->preventStrayPrompts();

    $turn = decideTurn($guest, $hotel, null);

    expect($turn->eligible)->toBeFalse()
        ->and($turn->decision->stay_id)->toBeNull()
        ->and(gateOf($turn, PitchGate::NO_STAY)['passed'])->toBeFalse();
});

it('blocks for the rest of the stay after an escalation', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $escalation = guestSignalTask($guest, GuestSignal::ESCALATION, ['status' => TaskStatus::COMPLETED]);
    // Two days ago and long resolved — it still blocks until they leave.
    Task::whereKey($escalation->id)->update(['created_at' => '2026-09-17 18:00:00']);
    TurnSignalAgent::fake()->preventStrayPrompts();

    expect(gateOf(decideTurn($guest, $hotel, $reservation), PitchGate::ESCALATED_THIS_STAY)['passed'])->toBeFalse();
});

it('ignores an escalation from an earlier stay', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $escalation = guestSignalTask($guest, GuestSignal::ESCALATION);
    Task::whereKey($escalation->id)->update(['created_at' => '2026-03-02 10:00:00']);
    classifierSays();
    pitchActivity($hotel);

    expect(gateOf(decideTurn($guest, $hotel, $reservation), PitchGate::ESCALATED_THIS_STAY)['passed'])->toBeTrue();
});

it('blocks while a service request from the last 24 hours is open', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    guestSignalTask($guest, GuestSignal::SERVICE_REQUEST, ['status' => TaskStatus::IN_PROGRESS]);
    TurnSignalAgent::fake()->preventStrayPrompts();

    expect(gateOf(decideTurn($guest, $hotel, $reservation), PitchGate::OPEN_SERVICE_REQUEST)['passed'])->toBeFalse();
});

it('does not block on an old, a finished, or a booking follow-up task', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $old = guestSignalTask($guest, GuestSignal::SERVICE_REQUEST);
    Task::whereKey($old->id)->update(['created_at' => now()->subHours(25)]);
    guestSignalTask($guest, GuestSignal::SERVICE_REQUEST, ['status' => TaskStatus::COMPLETED]);
    guestSignalTask($guest, GuestSignal::BOOKING_FOLLOW_UP);
    classifierSays();
    pitchActivity($hotel);

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(gateOf($turn, PitchGate::OPEN_SERVICE_REQUEST)['passed'])->toBeTrue()
        ->and($turn->eligible)->toBeTrue();
});

it('does not run the classifier when a cheap gate fails', function () {
    config(['pitching.enabled' => false]);
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    TurnSignalAgent::fake();

    $turn = decideTurn($guest, $hotel, $reservation);

    TurnSignalAgent::assertNeverPrompted();
    expect($turn->eligible)->toBeFalse()
        ->and($turn->decision->classifier_ran)->toBeFalse()
        ->and($turn->decision->complaint)->toBeNull()
        ->and(gateOf($turn, PitchGate::FEATURE_DISABLED)['passed'])->toBeFalse();
});

it('records every gate result, not just the first failure', function () {
    config(['pitching.enabled' => false]);
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel, ['planned_departure_date' => '2026-09-19']);
    guestSignalTask($guest, GuestSignal::SERVICE_REQUEST);
    TurnSignalAgent::fake()->preventStrayPrompts();

    $gates = collect(decideTurn($guest, $hotel, $reservation)->decision->gates);

    expect($gates->pluck('gate')->all())->toBe([
        'feature_disabled', 'no_stay', 'not_in_house', 'departing', 'escalated_this_stay', 'open_service_request',
    ])->and($gates->where('passed', false)->pluck('gate')->all())
        ->toBe(['feature_disabled', 'departing', 'open_service_request']);
});

// --- classifier -------------------------------------------------------------

it('fails closed when the classifier throws', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    TurnSignalAgent::fake(fn () => throw new RuntimeException('provider down'));

    $turn = decideTurn($guest, $hotel, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and($turn->decision->classifier_ran)->toBeTrue()
        ->and(gateOf($turn, PitchGate::CLASSIFIER_FAILED)['passed'])->toBeFalse();
});

it('treats a classifier quote not found in the message as a failure', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    classifierSays(['evidence_quote' => 'we are so bored']);

    $turn = decideTurn($guest, $hotel, $reservation, 'Hi! What can we do this evening?');

    expect($turn->eligible)->toBeFalse()
        ->and(gateOf($turn, PitchGate::CLASSIFIER_FAILED)['passed'])->toBeFalse();
});

it('accepts a quote that differs only in case and spacing', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    classifierSays(['evidence_quote' => 'WHAT  can we   do']);

    $turn = decideTurn($guest, $hotel, $reservation, "Hi! What can\nwe do this evening?");

    expect($turn->eligible)->toBeTrue()
        ->and($turn->decision->opening_quote)->toBe('WHAT  can we   do');
});

it('blocks a complaint even when it also opens the door', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    classifierSays(['complaint' => true, 'evidence_quote' => 'the pool was freezing']);

    $turn = decideTurn($guest, $hotel, $reservation, 'The room is fine but the pool was freezing, what else is there?');

    expect($turn->eligible)->toBeFalse()
        ->and($turn->decision->complaint)->toBeTrue()
        ->and(gateOf($turn, PitchGate::COMPLAINT_THIS_TURN)['passed'])->toBeFalse();
});

it('blocks when the message opens no door', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    classifierSays(['opening' => null, 'evidence_quote' => '']);

    $turn = decideTurn($guest, $hotel, $reservation, 'What time is checkout?');

    expect(gateOf($turn, PitchGate::NO_OPENING)['passed'])->toBeFalse()
        ->and($turn->decision->opening)->toBeNull();
});

it('ignores an interest category id from another hotel', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    $foreign = ActivityCategory::create(['hotel_id' => pitchHotel()->id, 'name' => 'Spa']);
    classifierSays(['opening' => 'asks_about_activities', 'interest_category_id' => $foreign->id]);

    $turn = decideTurn($guest, $hotel, $reservation);

    expect($turn->decision->interest_category_id)->toBeNull()
        ->and($turn->eligible)->toBeTrue();
});

it('only offers activities in the category the guest asked about', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $spa = ActivityCategory::create(['hotel_id' => $hotel->id, 'name' => 'Spa']);
    $massage = pitchActivity($hotel, ['name' => 'Hot Stone Massage', 'category_id' => $spa->id]);
    $boat = pitchActivity($hotel);
    classifierSays(['opening' => 'asks_about_activities', 'interest_category_id' => $spa->id]);

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(collect($turn->shortlist)->pluck('activityId')->all())->toBe([$massage->id])
        ->and(exclusionOf($turn, $boat)['reason'])->toBe(CandidateExclusion::OUTSIDE_INTEREST->value);
});

// --- opening gates ----------------------------------------------------------

/** A pitch already made this stay, as WP-17's tool will record it. */
function earlierPitch(Stay $stay, Reservation $reservation, bool $explicit = false, ?PitchResult $result = PitchResult::PITCHED): void
{
    $decision = PitchDecision::create([
        'hotel_id' => $stay->hotel_id,
        'guest_id' => $stay->guest_id,
        'stay_id' => $stay->id,
        'eligible' => true,
        'gates' => [],
        'explicit_request' => $explicit,
        'rules_version' => '1.0',
        'decided_at' => now()->subDay(),
        'result' => $result,
    ]);
    $activity = pitchActivity(Hotel::find($stay->hotel_id), ['name' => 'Kids Cooking Class']);
    $recommendation = Recommendation::create([
        'hotel_id' => $stay->hotel_id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'recommended_at' => now()->subDay(),
    ]);
    Recommendation::whereKey($recommendation->id)->update(['pitch_decision_id' => $decision->id]);
}

it('blocks a second unsolicited pitch in the same stay', function () {
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    earlierPitch($stay, $reservation);
    classifierSays(['opening' => 'boredom', 'evidence_quote' => 'bored']);

    $turn = decideTurn($guest, $hotel, $reservation, 'The kids are bored');

    expect($turn->eligible)->toBeFalse()
        ->and(gateOf($turn, PitchGate::PITCH_CAP)['passed'])->toBeFalse();
});

it('does not count a pitch whose reply failed, or one the guest asked for', function () {
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    earlierPitch($stay, $reservation, result: PitchResult::REPLY_FAILED);
    earlierPitch($stay, $reservation, explicit: true);
    classifierSays(['opening' => 'boredom', 'evidence_quote' => 'bored']);

    $turn = decideTurn($guest, $hotel, $reservation, 'The kids are bored');

    expect(gateOf($turn, PitchGate::PITCH_CAP)['passed'])->toBeTrue();
});

it('allows a suggestion on explicit request after the cap is reached', function () {
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    earlierPitch($stay, $reservation);
    pitchActivity($hotel, ['name' => 'Snorkelling Trip']);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect($turn->eligible)->toBeTrue()
        ->and($turn->decision->explicit_request)->toBeTrue()
        ->and(gateOf($turn, PitchGate::PITCH_CAP)['passed'])->toBeTrue();
});

it('blocks after any refusal this stay, including a low-confidence one', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $activity = pitchActivity($hotel);
    // The tool sets REJECTED on an explicit no even when the outcome was
    // floored to DELIVERED for low confidence; the gate reads the status.
    Recommendation::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'status' => RecommendationStatus::REJECTED,
        'recommended_at' => now()->subDay(),
    ]);
    classifierSays(['opening' => 'evening_plans', 'evidence_quote' => 'this evening']);

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(gateOf($turn, PitchGate::DECLINED_THIS_STAY)['passed'])->toBeFalse();
});

// --- candidates -------------------------------------------------------------

it('excludes a multi-day activity with too few consecutive open days before departure', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $course = pitchActivity($hotel, ['name' => 'PADI Open Water', 'duration_days' => 4]);
    $twoDay = pitchActivity($hotel, ['name' => 'Desert Camp', 'duration_days' => 2]);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(exclusionOf($turn, $course)['reason'])->toBe(CandidateExclusion::NOT_ENOUGH_DAYS->value)
        ->and(exclusionOf($turn, $course)['detail'])->toBe('Needs 4 consecutive open days; the longest run is 3.')
        // A two-day trip can start on the 19th or the 20th, not the 21st.
        ->and(collect($turn->shortlist)->firstWhere('activityId', $twoDay->id)->openDates)->toBe(['2026-09-19', '2026-09-20']);
});

it('treats a null duration as a single day', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $activity = pitchActivity($hotel, ['duration_days' => null]);
    classifierSays();

    $candidate = collect(decideTurn($guest, $hotel, $reservation)->shortlist)->firstWhere('activityId', $activity->id);

    expect($candidate->openDates)->toBe(['2026-09-19', '2026-09-20', '2026-09-21']);
});

it('treats a null timeframe as open every day', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect($turn->decision->candidates['shortlist'][0]['open_dates'])->toBe(['2026-09-19', '2026-09-20', '2026-09-21'])
        ->and($turn->decision->candidates['shortlist'][0]['capacity'])->toBe('unknown');
});

it('excludes an activity closed on every remaining date by season, closure or weekday hours', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $seasonOver = pitchActivity($hotel, ['name' => 'Whale Watching', 'available_until' => '2026-09-18']);
    $closed = pitchActivity($hotel, ['name' => 'Dune Bashing', 'unavailable_periods' => [
        ['start_date' => '2026-09-19', 'end_date' => '2026-09-21', 'reason' => 'Maintenance'],
    ]]);
    // The 19th–21st are Saturday to Monday.
    $weekdaysOnly = pitchActivity($hotel, ['name' => 'Pottery Class', 'operating_hours' => [
        'tuesday' => [['start' => '10:00', 'end' => '12:00']],
    ]]);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    foreach ([$seasonOver, $closed, $weekdaysOnly] as $activity) {
        expect(exclusionOf($turn, $activity)['reason'])->toBe(CandidateExclusion::CLOSED_ON_ALL_DATES->value);
    }

    expect($turn->eligible)->toBeFalse()
        ->and(gateOf($turn, PitchGate::NO_CANDIDATES)['passed'])->toBeFalse();
});

it('does not count today as open once today\'s last slot has ended', function () {
    $hotel = pitchHotel();
    // Leaves tomorrow, so today is the only day left. It is 15:00 locally.
    [$guest, $reservation] = pitchGuest($hotel, ['planned_departure_date' => '2026-09-20']);
    $morning = pitchActivity($hotel, ['name' => 'Morning Yoga', 'operating_hours' => [
        'saturday' => [['start' => '07:00', 'end' => '09:00'], ['start' => '12:00', 'end' => '15:00']],
    ]]);
    $evening = pitchActivity($hotel, ['name' => 'Night Snorkel', 'operating_hours' => [
        'saturday' => [['start' => '19:00', 'end' => '21:00']],
    ]]);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    // A slot ending exactly now is over.
    expect(exclusionOf($turn, $morning)['reason'])->toBe(CandidateExclusion::CLOSED_ON_ALL_DATES->value)
        ->and(collect($turn->shortlist)->pluck('activityId')->all())->toBe([$evening->id]);
});

it('excludes an activity full on every remaining open date but not one with unknown capacity', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $limited = pitchActivity($hotel, ['name' => 'Private Cruise', 'daily_capacity' => 4]);
    $unlimited = pitchActivity($hotel, ['name' => 'Beach Volleyball']);

    // Another guest fills the cruise on each of the three days, at 20:00 local.
    [$other] = pitchGuest($hotel);
    foreach (['2026-09-19', '2026-09-20', '2026-09-21'] as $date) {
        foreach ([$limited, $unlimited] as $activity) {
            wp5Booking($hotel, [
                'guest_id' => $other->id,
                'activity_id' => $activity->id,
                'pax' => 4,
                'scheduled_for' => Carbon::parse("{$date} 20:00", 'Asia/Riyadh')->utc(),
            ]);
        }
    }
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(exclusionOf($turn, $limited)['reason'])->toBe(CandidateExclusion::NO_CAPACITY->value)
        ->and(collect($turn->shortlist)->pluck('activityId')->all())->toBe([$unlimited->id]);
});

it('keeps a limited activity on the days that still have room', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $limited = pitchActivity($hotel, ['name' => 'Private Cruise', 'daily_capacity' => 4]);
    [$other] = pitchGuest($hotel);
    wp5Booking($hotel, [
        'guest_id' => $other->id,
        'activity_id' => $limited->id,
        'pax' => 3,
        'scheduled_for' => Carbon::parse('2026-09-20 10:00', 'Asia/Riyadh')->utc(),
    ]);
    wp5Booking($hotel, [
        'guest_id' => $other->id,
        'activity_id' => $limited->id,
        'pax' => 1,
        'scheduled_for' => Carbon::parse('2026-09-20 18:00', 'Asia/Riyadh')->utc(),
    ]);
    classifierSays();

    $candidate = decideTurn($guest, $hotel, $reservation)->decision->candidates['shortlist'][0];

    expect($candidate['open_dates'])->toBe(['2026-09-19', '2026-09-21'])
        ->and($candidate['capacity'])->toBe('known');
});

it('excludes an activity already booked this stay', function () {
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    $booked = pitchActivity($hotel);
    wp5Booking($hotel, ['guest_id' => $guest->id, 'stay_id' => $stay->id, 'activity_id' => $booked->id]);
    classifierSays();

    expect(exclusionOf(decideTurn($guest, $hotel, $reservation), $booked)['reason'])
        ->toBe(CandidateExclusion::ALREADY_BOOKED->value);
});

it('excludes an activity that clashes with a same-category booking on every date', function () {
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    $spa = ActivityCategory::create(['hotel_id' => $hotel->id, 'name' => 'Spa']);
    $facial = pitchActivity($hotel, ['name' => 'Facial', 'category_id' => $spa->id]);
    $massage = pitchActivity($hotel, ['name' => 'Massage', 'category_id' => $spa->id]);
    foreach (['2026-09-19', '2026-09-20', '2026-09-21'] as $date) {
        wp5Booking($hotel, [
            'guest_id' => $guest->id,
            'stay_id' => $stay->id,
            'activity_id' => $facial->id,
            'scheduled_for' => Carbon::parse("{$date} 16:00", 'Asia/Riyadh')->utc(),
        ]);
    }
    wp5Booking($hotel, ['guest_id' => $guest->id, 'stay_id' => $stay->id, 'item_name' => 'Minibar']);
    classifierSays();

    $turn = decideTurn($guest, $hotel, $reservation);

    expect(exclusionOf($turn, $massage)['reason'])->toBe(CandidateExclusion::CLASHES_ON_ALL_DATES->value)
        ->and($turn->decision->candidates['unscheduled_bookings'])->toBe(1);
});

it('keeps the shortlist to the configured size', function () {
    config(['pitching.shortlist_size' => 2]);
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    foreach (['Archery', 'Bowling', 'Cycling'] as $name) {
        pitchActivity($hotel, ['name' => $name]);
    }
    classifierSays();

    $candidates = decideTurn($guest, $hotel, $reservation)->decision->candidates;

    expect($candidates['considered'])->toBe(3)
        ->and(array_column($candidates['shortlist'], 'name'))->toBe(['Archery', 'Bowling'])
        ->and(array_column($candidates['shortlist'], 'rank'))->toBe([1, 2]);
});

// --- the decision row -------------------------------------------------------

it('writes a decision row for an ineligible turn', function () {
    config(['pitching.enabled' => false]);
    $hotel = pitchHotel();
    [$guest, $reservation, $stay] = pitchGuest($hotel);
    TurnSignalAgent::fake()->preventStrayPrompts();

    $turn = decideTurn($guest, $hotel, $reservation);
    app(PitchCoordinator::class)->complete($turn);

    $decision = PitchDecision::sole();

    expect($decision->stay_id)->toBe($stay->id)
        ->and($decision->eligible)->toBeFalse()
        ->and($decision->result)->toBe(PitchResult::INELIGIBLE)
        ->and($decision->completed_at)->not->toBeNull()
        ->and($decision->rules_version)->toBe('1.0')
        ->and($decision->signals['party'])->toBe(['adults' => 2, 'children' => 1]);
});

it('refuses to update decision columns once written', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    classifierSays();
    pitchActivity($hotel);

    $decision = decideTurn($guest, $hotel, $reservation)->decision;

    expect(fn () => $decision->update(['eligible' => false]))->toThrow(RuntimeException::class);

    $decision->refresh()->update(['result' => PitchResult::NO_PITCH, 'completed_at' => now()]);

    // Completion is written once as well.
    expect(fn () => $decision->refresh()->update(['result' => PitchResult::PITCHED]))->toThrow(RuntimeException::class)
        ->and($decision->refresh()->result)->toBe(PitchResult::NO_PITCH);
});

// --- the job ----------------------------------------------------------------

function pitchJob(Guest $guest, Reservation $reservation, string $message = 'Hi! What can we do this evening?'): ProcessInboundWhatsAppMessageJob
{
    return new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => $guest->phone_number, 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: $guest->phone_number,
        messageText: $message,
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $guest->hotel,
        reservation: $reservation,
        devicePaired: false,
    );
}

it('writes exactly one completed decision per guest turn', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    pitchActivity($hotel);
    classifierSays();
    GuestConciergeAgent::fake(['We have a sunset catamaran tonight!']);

    pitchJob($guest, $reservation)->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    $decision = PitchDecision::sole();

    // Eligible, but nothing can pitch until WP-17 adds the tool.
    expect($decision->eligible)->toBeTrue()
        ->and($decision->result)->toBe(PitchResult::NO_PITCH)
        ->and($decision->classifier_ran)->toBeTrue();
});

it('still replies to the guest when the eligibility service throws', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    GuestConciergeAgent::fake(['Checkout is at noon.']);
    $this->mock(PitchEligibilityService::class, fn ($mock) => $mock
        ->shouldReceive('evaluateCheapGates')->andThrow(new RuntimeException('database hiccup')));

    $job = pitchJob($guest, $reservation, 'What time is checkout?');
    $job->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    Http::assertSent(fn ($request) => $request['text']['body'] === 'Checkout is at noon.');
    expect($job->inbound->fresh()->status)->toBe(InboundMessageStatus::REPLIED)
        ->and(PitchDecision::count())->toBe(0);
});

// --- same-turn backstop (Layer 3) and task labels ---------------------------

it('marks the turn and the task when the concierge escalates', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $turn = new PitchTurn(null, eligible: true);

    (new EscalateToHumanTool($guest, $hotel, $reservation, $turn))->handle(new Request(['reason' => 'Wants a refund']));

    $task = Task::sole();

    expect($turn->mayPitch())->toBeFalse()
        ->and($task->guest_signal)->toBe(GuestSignal::ESCALATION)
        ->and($task->reservation_id)->toBe($reservation->id);
});

it('blocks the turn for a service request but not for a booking follow-up', function () {
    $hotel = pitchHotel();
    [$guest, $reservation] = pitchGuest($hotel);
    $followUpTurn = new PitchTurn(null, eligible: true);
    $serviceTurn = new PitchTurn(null, eligible: true);

    (new CreateGuestServiceRequestTool($guest, $hotel, $reservation, $followUpTurn))->handle(new Request([
        'title' => 'Help book the catamaran', 'description' => 'Very keen', 'kind' => 'booking_follow_up',
    ]));
    // No kind given: the conservative default, a service request.
    (new CreateGuestServiceRequestTool($guest, $hotel, $reservation, $serviceTurn))->handle(new Request([
        'title' => 'AC broken', 'description' => 'Room 214',
    ]));

    expect($followUpTurn->mayPitch())->toBeTrue()
        ->and($serviceTurn->mayPitch())->toBeFalse()
        ->and(Task::orderBy('title')->pluck('guest_signal')->all())
        ->toBe([GuestSignal::SERVICE_REQUEST, GuestSignal::BOOKING_FOLLOW_UP]);
});
