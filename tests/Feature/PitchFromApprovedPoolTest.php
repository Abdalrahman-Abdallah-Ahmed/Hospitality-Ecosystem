<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\GetRecommendationsTool;
use App\Ai\Tools\PitchActivityTool;
use App\Enums\PitchGate;
use App\Support\Pitching\PitchTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(fn () => rapPitchingOn());

/**
 * SPEC-071 FR-013: the Concierge pitches only approved recommendations that
 * were never offered and whose activity is still active.
 */
it('shortlists only approved recommendations that were never offered', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $approved = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');
    rapRecommendation($reservation, rapActivity($hotel, 'Pending'), 'pending_approval');
    rapRecommendation($reservation, rapActivity($hotel, 'Refused'), 'rejected_by_admin');
    rapRecommendation($reservation, rapActivity($hotel, 'Expired'), 'expired');
    rapRecommendation($reservation, rapActivity($hotel, 'Delivered'), 'approved', ['delivered_at' => now()]);
    $inactive = rapActivity($hotel, 'Closed');
    $inactive->update(['is_active' => false]);
    rapRecommendation($reservation, $inactive, 'approved');

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeTrue()
        ->and(collect($turn->shortlist)->pluck('recommendationId')->all())->toBe([$approved->id]);
});

it('says nothing is approved yet when only pending recommendations exist', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');
    rapRecommendation($reservation, rapActivity($hotel, 'Spa'), 'pending_approval');

    $turn = rapTurn($guest, $reservation);

    expect($turn->eligible)->toBeFalse()
        ->and(collect($turn->decision->gates)->firstWhere('gate', PitchGate::NO_CANDIDATES->value)['detail'])
        ->toBe('Nothing approved; 2 pending approval.');
});

it('lists only recommendations the guest was offered', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $offered = rapRecommendation($reservation, rapActivity($hotel), 'sent', ['delivered_at' => now()]);
    rapRecommendation($reservation, rapActivity($hotel, 'Approved'), 'approved');
    rapRecommendation($reservation, rapActivity($hotel, 'Pending'), 'pending_approval');
    rapRecommendation($reservation, rapActivity($hotel, 'Refused'), 'rejected_by_admin');

    $listed = json_decode((string) (new GetRecommendationsTool($reservation))->handle(new Request([])), true);

    expect(collect($listed)->pluck('id')->all())->toBe([$offered->id]);
});

it('names the one approved offer only on a turn that may pitch', function () {
    $hotel = rapHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved', ['reason' => 'Loves the sea']);

    $eligible = new GuestConciergeAgent($guest, $hotel, $reservation, rapTurn($guest, $reservation));
    $ineligible = new GuestConciergeAgent($guest, $hotel, $reservation, PitchTurn::ineligible());
    $none = new GuestConciergeAgent($guest, $hotel, $reservation);

    expect((string) $eligible->instructions())
        ->toContain('mention Snorkeling — Loves the sea')
        ->not->toContain('tailor a recommendation yourself')
        ->and(collect($eligible->tools())->contains(fn ($tool) => $tool instanceof PitchActivityTool))->toBeTrue();

    foreach ([$ineligible, $none] as $agent) {
        expect((string) $agent->instructions())
            ->toContain('Do not suggest activities the guest did not ask about')
            ->not->toContain('Snorkeling')
            ->and(collect($agent->tools())->contains(fn ($tool) => $tool instanceof PitchActivityTool))->toBeFalse();
    }
});
