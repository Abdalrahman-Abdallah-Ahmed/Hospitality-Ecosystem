<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\CheckInTool;
use App\Ai\Tools\CheckOutTool;
use App\Ai\Tools\GetStaysTool;
use App\Enums\ActorKind;
use App\Enums\Permission;
use App\Enums\StayStatus;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;

/*
| The Admin AI checks guests in and out through the same service, rules and
| permissions as the front desk (spec User Story 5, FR-023–FR-025).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function aiCall(object $tool, array $input): string
{
    return (string) $tool->handle(new ToolRequest($input));
}

it('checks a reservation in, audited as the AI acting for the admin', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    $this->actingAs($hotel->owner);

    $answer = aiCall(new CheckInTool($hotel, $hotel->owner), ['reservation_id' => $reservation->reservation_id]);

    expect($answer)->toContain('Checked in')->toContain('checked_in')
        ->and(collect(fdStays($reservation))->pluck('status')->unique()->all())->toBe([StayStatus::IN_HOUSE]);

    $audit = EventLog::where('event_type', 'stay.checked_in')->get();
    expect($audit)->toHaveCount(2)
        ->and($audit->pluck('actor_kind')->map(fn ($kind) => $kind->value ?? $kind)->unique()->all())->toBe([ActorKind::AI_AGENT->value])
        ->and($audit->pluck('actor_id')->unique()->all())->toBe([$hotel->owner->id]);
});

it('assigns a named room to an unassigned line at check-in', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [null]);

    aiCall(new CheckInTool($hotel, $hotel->owner), [
        'reservation_id' => $reservation->reservation_id,
        'assign' => [['room_type' => 'Deluxe', 'room_number' => $a->room_number]],
    ]);

    expect(fdStays($reservation)[0]->fresh()->room_id)->toBe($a->id)
        ->and(fdStays($reservation)[0]->fresh()->status)->toBe(StayStatus::IN_HOUSE);
});

it('reports the same reason the front desk gets, and changes nothing', function () {
    [$hotel, $type] = fdHotel();
    $reservation = fdBook($hotel, $type, [null]);

    $answer = aiCall(new CheckInTool($hotel, $hotel->owner), ['reservation_id' => $reservation->reservation_id]);

    expect($answer)->toBe('Not done: Assign or name a room first.')
        ->and(fdStays($reservation)[0]->fresh()->status)->toBe(StayStatus::EXPECTED);
});

it('refuses for a user without the permission', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);
    $employee = fdEmployee($hotel, [Permission::STAYS_VIEW]);

    expect(aiCall(new CheckInTool($hotel, $employee), ['reservation_id' => $reservation->reservation_id]))
        ->toBe('You do not have permission to check guests in.')
        ->and(aiCall(new CheckOutTool($hotel, $employee), ['reservation_id' => $reservation->reservation_id]))
        ->toBe('You do not have permission to check guests out.')
        ->and(aiCall(new GetStaysTool($hotel, fdEmployee($hotel, [])), ['list' => 'arrivals']))
        ->toBe('You do not have permission to view stays.')
        ->and(fdStays($reservation)[0]->fresh()->status)->toBe(StayStatus::EXPECTED);
});

it('checks only the named rooms out, with a cleaning task each', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    aiCall(new CheckInTool($hotel, $hotel->owner), ['reservation_id' => $reservation->reservation_id]);

    $answer = aiCall(new CheckOutTool($hotel, $hotel->owner), ['reservation_id' => $reservation->reservation_id, 'room_numbers' => [$a->room_number]]);

    [$first, $second] = fdStays($reservation);
    expect($answer)->toContain('Checked out')
        ->and($first->fresh()->status)->toBe(StayStatus::DEPARTED)
        ->and($second->fresh()->status)->toBe(StayStatus::IN_HOUSE)
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('lists arrivals the same way the endpoint does', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, null]);

    $result = json_decode(aiCall(new GetStaysTool($hotel, $hotel->owner), ['list' => 'arrivals']), true);
    $endpoint = fdGet($this, $hotel->owner, '/api/stays/arrivals')->json('body.stays');

    expect($result['stays'])->toHaveCount(count($endpoint))
        ->and(collect($result['stays'])->pluck('reservation')->unique()->all())->toBe([$reservation->reservation_id])
        ->and(collect($result['stays'])->pluck('room_number')->sort()->values()->all())->toBe(collect([$a->room_number, 'unassigned'])->sort()->values()->all());
});

it('never finds another hotel\'s reservation', function () {
    [$hotel, $type, [$a]] = fdHotel();
    [$other] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id]);

    expect(aiCall(new CheckInTool($other, $other->owner), ['reservation_id' => $reservation->reservation_id]))
        ->toBe('This hotel has no reservation with that code.');
});

it('gives the Admin AI the three tools and the Concierge none, telling guests to ask the front desk', function () {
    [$hotel] = fdHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-ai', 'channel' => 'booking_com']);

    $adminTools = collect((new AdminAdvisorAgent($hotel->owner))->tools())->map(fn ($tool) => $tool::class);
    expect($adminTools)->toContain(CheckInTool::class, CheckOutTool::class, GetStaysTool::class);

    $concierge = new GuestConciergeAgent($guest, $hotel);
    $conciergeTools = collect($concierge->tools())->map(fn ($tool) => $tool::class);
    expect($conciergeTools)->not->toContain(CheckInTool::class)->not->toContain(CheckOutTool::class)
        ->and((string) $concierge->instructions())->toContain('front desk');
});
