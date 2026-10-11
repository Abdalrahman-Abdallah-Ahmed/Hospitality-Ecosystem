<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\EscalateToHumanTool;
use App\Models\GuestMemory;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-084 FR-016–FR-018, FR-032, FR-037: the Concierge gets this guest's
 * memories at this hotel as hints, and passes requests about them to staff.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
});

function conciergeInstructions($guest, $hotel, $reservation = null): string
{
    return (string) GuestConciergeAgent::make(guest: $guest, hotel: $hotel, reservation: $reservation)->instructions();
}

it('gives the Concierge the guest\'s memories at this hotel, as hints it must not recite', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    cmaMemory($guest, ['category' => 'preference', 'content' => 'Vegetarian.']);
    cmaMemory($guest, ['category' => 'interest', 'content' => 'Loved the sunset camel ride.', 'confidence' => 'medium']);

    $instructions = conciergeInstructions($guest, $hotel, $reservation);

    expect($instructions)
        ->toContain('What you may remember about this guest from earlier stays')
        ->toContain('- [preference] Vegetarian.')
        ->toContain('- [interest] Loved the sunset camel ride.')
        ->toContain('always win over these')
        ->toContain('Never list, quote or mention');
});

it('gives no memory section when there are none, or the hotel switched memories off', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);

    expect(conciergeInstructions($guest, $hotel, $reservation))->not->toContain('What you may remember');

    cmaMemory($guest, ['content' => 'Vegetarian.']);
    $hotel->forceFill(['guest_memories_enabled' => false])->save();

    expect(conciergeInstructions($guest, $hotel->fresh(), $reservation))->not->toContain('Vegetarian.');
});

it('never gives one guest\'s or one hotel\'s memories to another', function () {
    $hotelA = cmaHotel();
    $hotelB = cmaHotel();
    [$guestA, $reservationA] = rapInHouseStay($hotelA);
    $samePhoneAtB = gsGuest($hotelB, ['phone_number' => $guestA->phone_number]);
    $neighbour = gsGuest($hotelA);
    cmaMemory($samePhoneAtB, ['content' => 'Memory from hotel B.']);
    cmaMemory($neighbour, ['content' => 'Another guest.']);
    cmaMemory($guestA, ['content' => 'Mine.']);

    $instructions = conciergeInstructions($guestA, $hotelA, $reservationA);

    expect($instructions)->toContain('Mine.')
        ->not->toContain('Memory from hotel B.')
        ->not->toContain('Another guest.');
});

it('forgets a deleted memory on the very next turn', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $memory = cmaMemory($guest, ['content' => 'Vegetarian.']);

    $memory->delete();

    expect(conciergeInstructions($guest, $hotel, $reservation))->not->toContain('Vegetarian.');
});

it('passes a request to forget something to staff, and deletes nothing itself', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    cmaMemory($guest, ['content' => 'Vegetarian.']);

    (new EscalateToHumanTool($guest, $hotel, $reservation))->handle(new Request([
        'reason' => 'The guest asked us to forget a preference.',
        'request_type' => 'forget_memory',
        'detail' => 'vegetarian',
    ]));

    $task = Task::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->sole();

    expect($task->title)->toStartWith('Memory request — forget: vegetarian')
        ->and($task->description)->toContain('forget a preference')
        ->and(GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->count())->toBe(1);
});

it('passes a request to see what is remembered to staff', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);

    (new EscalateToHumanTool($guest, $hotel, $reservation))->handle(new Request([
        'reason' => 'The guest asked what we know about them.',
        'request_type' => 'see_memories',
    ]));

    expect(Task::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->sole()->title)->toStartWith('Memory request — see memories');
});

it('tells the Concierge how to handle a guest asking about their memories', function () {
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    cmaMemory($guest, ['content' => 'Vegetarian.']);

    expect(conciergeInstructions($guest, $hotel, $reservation))
        ->toContain('request_type')
        ->toContain('stop using that note for the rest of this conversation');
});
