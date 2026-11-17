<?php

use App\Enums\PitchGate;
use App\Models\Activity;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Pitching\PitchCoordinator;
use App\Support\Pitching\PitchTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => rapPitchingOn());

function retryGate(PitchTurn $turn, PitchGate $gate): ?array
{
    return collect($turn->decision->gates)->firstWhere('gate', $gate->value);
}

/**
 * D10 / SPEC-074: after a declined pitch, one different approved activity may
 * follow within 24 hours of the first pitch; then the stay is closed.
 *
 * @return array{0: Guest, 1: Reservation, 2: Hotel}
 */
function declinedFirstPitch(array $roomNumbers = ['501']): array
{
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel, [], $roomNumbers);
    rapRecommendation($reservation, rapActivity($hotel, 'Sunset cruise'), 'approved', ['priority' => 1]);
    rapRecommendation($reservation, rapActivity($hotel, 'Cooking class'), 'approved', ['priority' => 2]);
    rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'approved', ['priority' => 3]);

    $first = rapPitch($guest, $reservation);
    rapGuestSays($first, $reservation, 'rejected');

    return [$guest, $reservation, $hotel];
}

it('allows one different activity within 24 hours of a declined pitch', function () {
    [$guest, $reservation] = declinedFirstPitch();
    $this->travel(2)->hours();

    $retry = rapPitch($guest, $reservation);

    expect($retry->staged()->name)->toBe('Cooking class')
        ->and($retry->decision->fresh()->is_retry)->toBeTrue()
        ->and($retry->decision->rules_version)->toBe('2.0');
});

it('closes the stay once the retry has been made, whatever the answer', function (string $answer) {
    [$guest, $reservation] = declinedFirstPitch();
    $this->travel(2)->hours();
    $retry = rapPitch($guest, $reservation);
    rapGuestSays($retry, $reservation, $answer);
    $this->travel(1)->hours();

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and(retryGate($turn, PitchGate::RETRY_USED)['passed'])->toBeFalse();
})->with(['rejected', 'dismissed']);

it('closes the stay 24 hours after the declined flow began', function () {
    [$guest, $reservation] = declinedFirstPitch();
    $this->travel(24 * 60 + 1)->minutes();

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and(retryGate($turn, PitchGate::RETRY_WINDOW_CLOSED)['passed'])->toBeFalse();
});

it('never offers the declined activity again, even when asked', function () {
    [$guest, $reservation, $hotel] = declinedFirstPitch();
    // A later generation suggested the same activity again, and it was approved.
    $cruise = Activity::where('name', 'Sunset cruise')->sole();
    rapRecommendation($reservation, $cruise, 'approved', ['priority' => 0]);
    rapClassifierSays('asks_what_to_do', 'what can I do');

    $turn = rapTurn($guest, $reservation, 'What can I do tomorrow?');

    expect(collect($turn->shortlist)->pluck('name')->all())->not->toContain('Sunset cruise')
        ->and(collect($turn->decision->candidates['excluded'])->firstWhere('name', 'Sunset cruise')['reason'])->toBe('declined_activity');
});

it('still answers an explicit request after the stay is closed, without counting it as the retry', function () {
    [$guest, $reservation] = declinedFirstPitch();
    $this->travel(25)->hours();
    rapClassifierSays('asks_what_to_do', 'what can I do');

    $turn = rapPitch($guest, $reservation, 'What can I do tomorrow?', 'what can I do');

    expect($turn->decision->explicit_request)->toBeTrue()
        ->and($turn->decision->fresh()->is_retry)->toBeFalse()
        ->and(retryGate($turn, PitchGate::RETRY_WINDOW_CLOSED)['passed'])->toBeTrue();
});

it('does not count the retry toward the per-stay cap', function () {
    config(['pitching.max_unsolicited_per_stay' => 1]);
    [$guest, $reservation] = declinedFirstPitch();

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeTrue()
        ->and(retryGate($turn, PitchGate::PITCH_CAP))->toMatchArray(['passed' => true, 'detail' => 'Retry after a decline: the cap does not apply.']);
});

it('keeps the cap after an accepted pitch: that is not a decline', function () {
    config(['pitching.max_unsolicited_per_stay' => 1]);
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel, 'Sunset cruise'), 'approved');
    rapRecommendation($reservation, rapActivity($hotel, 'Cooking class'), 'approved');
    $first = rapPitch($guest, $reservation);
    rapGuestSays($first, $reservation, 'accepted');

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and(retryGate($turn, PitchGate::PITCH_CAP)['passed'])->toBeFalse()
        ->and($turn->isRetryTurn)->toBeFalse();
});

it('shares one flow across every room of the reservation', function () {
    [$guest, $reservation] = declinedFirstPitch(['601', '602']);
    $this->travel(2)->hours();
    rapPitch($guest, $reservation);

    // Another stay of the same reservation: the retry is already spent.
    $otherStay = $reservation->stays()->where('id', '!=', $reservation->stay->id)->first();
    $turn = app(PitchCoordinator::class)->begin($guest, $guest->hotel, $reservation->setRelation('stay', $otherStay), 'Any plans for this evening?', null);

    expect($turn->eligible)->toBeFalse()
        ->and(retryGate($turn, PitchGate::RETRY_USED)['passed'])->toBeFalse();
});
