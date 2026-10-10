<?php

use App\Enums\ProactiveTrigger;
use App\Models\Booking;
use App\Models\ProactiveMessage;
use App\Services\Proactive\ProactiveGuardrails;
use App\Services\Proactive\ProactiveTriggerFinder;
use App\Services\Recommendations\RecommendationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    rapPitchingOn();
    // 07:00 UTC is 10:00 at a hotel in Riyadh (UTC+3).
    $this->travelTo(Carbon::parse('2026-10-10 07:00:00', 'UTC'));
    // Nothing is sent from these tests: they are about what gets scheduled.
    gsFakeWhatsApp(failures: 0);
    config(['pitching.enabled' => false]);
});

/**
 * SPEC-073 FR-025 / FR-029: which messages each trigger schedules, once.
 */
it('schedules nothing while proactive messaging is off', function () {
    $hotel = rapHotel();
    rapInHouseStay($hotel);

    rapSweep();

    expect(ProactiveMessage::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('schedules a first-morning message for a guest who checked in yesterday', function () {
    $hotel = rapProactiveOn(rapHotel());
    [$guest, $reservation] = rapInHouseStay($hotel);

    rapSweep();

    $row = rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::FIRST_MORNING);

    expect($row)->not->toBeNull()
        ->and($row->event_key)->toBe("first_morning:{$reservation->id}")
        ->and($row->due_at->toDateTimeString())->toBe('2026-10-10 07:00:00')
        ->and($row->valid_until->toDateTimeString())->toBe('2026-10-10 10:00:00');
});

it('schedules a mid-stay message only for stays of four nights or more', function () {
    $hotel = rapProactiveOn(rapHotel());
    [$long, $longReservation] = rapInHouseStay($hotel);
    [$short, $shortReservation] = rapInHouseStay($hotel);
    // Long: 8th to 12th (4 nights) → middle day the 10th. Short: 9th to 12th.
    $longReservation->forceFill(['arrival_date' => '2026-10-08', 'departure_date' => '2026-10-12'])->saveQuietly();
    $shortReservation->forceFill(['arrival_date' => '2026-10-09', 'departure_date' => '2026-10-12'])->saveQuietly();

    rapSweep();

    expect(rapProactiveRows($long)->pluck('trigger')->all())->toContain(ProactiveTrigger::MID_STAY)
        ->and(rapProactiveRows($short)->pluck('trigger')->all())->not->toContain(ProactiveTrigger::MID_STAY);
});

it('times booking reminders: the evening before a morning activity, hours before a later one', function (string $startsAt, ?string $due, ?string $validUntil) {
    $hotel = rapProactiveOn(rapHotel());
    [$guest, $reservation, $stay] = rapInHouseStay($hotel);
    // The booking service reads a scheduled time as hotel-local.
    $booking = abBook($hotel, rapActivity($hotel, 'Desert safari'), $startsAt, 1, [
        'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id, 'status' => 'confirmed',
    ]);

    rapSweep();

    $row = rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::UPCOMING_ACTIVITY);

    expect($row?->due_at?->setTimezone('Asia/Riyadh')->toDateTimeString())->toBe($due)
        ->and($row?->valid_until?->setTimezone('Asia/Riyadh')->toDateTimeString())->toBe($validUntil)
        ->and($row?->booking_id)->toBe($due ? $booking->id : null);
})->with([
    'morning activity tomorrow' => ['2026-10-11 08:00:00', '2026-10-10 18:00:00', '2026-10-11 07:00:00'],
    'afternoon activity today' => ['2026-10-10 15:00:00', '2026-10-10 11:00:00', '2026-10-10 14:00:00'],
]);

it('does not remind about a booking that is not confirmed', function () {
    $hotel = rapProactiveOn(rapHotel());
    [$guest, $reservation] = rapInHouseStay($hotel);
    abBook($hotel, rapActivity($hotel), '2026-10-11 08:00:00', 1, [
        'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'status' => 'pending',
    ]);

    rapSweep();

    expect(rapProactiveRows($guest)->pluck('trigger')->all())->not->toContain(ProactiveTrigger::UPCOMING_ACTIVITY);
});

it('schedules an offer as soon as a recommendation is approved for an in-house guest', function () {
    $hotel = rapProactiveOn(rapHotel(), ['triggers' => ['first_morning' => false]]);
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');

    app(RecommendationApprovalService::class)->approve($recommendation, rapAdmin($hotel));

    expect(rapProactiveRows($guest)->firstWhere('trigger', ProactiveTrigger::RECOMMENDATION_APPROVED)?->recommendation_id)->toBe($recommendation->id);
});

it('does not schedule an approved-recommendation offer for a guest already pitched this reservation', function () {
    config(['pitching.enabled' => true, 'pitching.max_unsolicited_per_stay' => 2]);
    $hotel = rapProactiveOn(rapHotel(), ['triggers' => ['first_morning' => false]]);
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel, 'Cruise'), 'approved');
    rapPitch($guest, $reservation);
    $second = rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'pending_approval');

    app(RecommendationApprovalService::class)->approve($second, rapAdmin($hotel));

    expect(rapProactiveRows($guest)->where('trigger', ProactiveTrigger::RECOMMENDATION_APPROVED))->toHaveCount(0);
});

it('never schedules the same message twice, and one per reservation however many rooms', function () {
    $hotel = rapProactiveOn(rapHotel());
    [$guest] = rapInHouseStay($hotel, [], ['701', '702', '703']);

    rapSweep();
    rapSweep();

    expect(rapProactiveRows($guest)->where('trigger', ProactiveTrigger::FIRST_MORNING))->toHaveCount(1);
});

it('skips a trigger the hotel switched off, and leaves other hotels alone', function () {
    $hotel = rapProactiveOn(rapHotel(), ['triggers' => ['first_morning' => false]]);
    $other = rapHotel();
    [$guest] = rapInHouseStay($hotel);
    [$otherGuest] = rapInHouseStay($other);

    rapSweep();

    expect(rapProactiveRows($guest)->where('trigger', ProactiveTrigger::FIRST_MORNING))->toHaveCount(0)
        ->and(rapProactiveRows($otherGuest))->toHaveCount(0);
});

it('looks at many guests with a fixed number of queries', function () {
    $hotel = rapProactiveOn(rapHotel());
    foreach (range(1, 12) as $i) {
        rapInHouseStay($hotel);
    }
    $finder = app(ProactiveTriggerFinder::class);

    $count = function () use ($finder, $hotel) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach (ProactiveTrigger::cases() as $trigger) {
            $finder->find($hotel, $trigger, now());
        }

        return count(DB::getQueryLog());
    };

    $withTwelve = $count();
    rapInHouseStay($hotel);
    rapInHouseStay($hotel);

    expect($count())->toBe($withTwelve);
});

it('offers an approved recommendation even when it is beyond the shortlist', function () {
    config(['pitching.enabled' => true, 'pitching.shortlist_size' => 3]);
    $hotel = rapProactiveOn(rapHotel());
    [$guest, $reservation, $stay] = rapInHouseStay($hotel);
    rapInbound($guest, now()->subHours(2));
    foreach (range(1, 4) as $i) {
        rapRecommendation($reservation, rapActivity($hotel, "Activity {$i}"), 'approved', ['priority' => $i]);
    }
    $fifth = rapRecommendation($reservation, rapActivity($hotel, 'Activity 5'), 'approved', ['priority' => 5]);
    $row = tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => $reservation->id, 'stay_id' => $stay->id,
        'recommendation_id' => $fifth->id, 'trigger' => 'recommendation_approved', 'event_key' => "recommendation:{$fifth->id}",
        'status' => 'scheduled', 'due_at' => now(), 'valid_until' => now()->addDay(),
    ]))->save();

    $result = app(ProactiveGuardrails::class)->check($row->fresh(), now());

    expect($result->sends())->toBeTrue()
        ->and($result->recommendation->id)->toBe($fifth->id);
});
