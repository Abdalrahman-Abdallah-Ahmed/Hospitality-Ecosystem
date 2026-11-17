<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Enums\KnowledgeAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function knSearchToolOf(iterable $tools): KnowledgeSearchTool
{
    // The Admin agent's tools are wrapped in the guard; the others are not.
    return collect($tools)
        ->map(fn ($tool) => $tool instanceof GuardedTool ? $tool->inner() : $tool)
        ->first(fn ($tool) => $tool instanceof KnowledgeSearchTool);
}

it('gives the Concierge the guest view of the knowledge search, and staff agents the staff view', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    expect(knSearchToolOf((new GuestConciergeAgent($guest, $hotel, $reservation))->tools())->audience())
        ->toBe(KnowledgeAudience::GUEST);
    expect(knSearchToolOf((new AdminAdvisorAgent($admin))->tools())->audience())
        ->toBe(KnowledgeAudience::STAFF);
    expect(knSearchToolOf((new RecommendationAgent($hotel, $reservation))->tools())->audience())
        ->toBe(KnowledgeAudience::STAFF);
});

it('tells the Concierge how to cite sources, which one wins, and what to do when nothing is found', function () {
    [, $hotel] = gsHotel();
    $instructions = (string) (new GuestConciergeAgent(gsGuest($hotel), $hotel))->instructions();

    expect($instructions)
        ->toContain('scope is "hotel", name it by its title, in the guest\'s')
        ->toContain('say it is general information')
        ->toContain('follow the "hotel" result')
        ->toContain('Only cite results the tool returned')
        ->toContain('offer to pass the question to staff')
        ->toContain('never for')
        ->toContain('availability, prices on a date, bookings or statuses');
});

it('tells the Admin Advisor to cite title, location and date, and to follow the hotel source', function () {
    [$admin] = gsHotel();
    $instructions = (string) (new AdminAdvisorAgent($admin))->instructions();

    expect($instructions)
        ->toContain('title — location (updated date)')
        ->toContain('follow the "hotel" result')
        ->toContain('Only cite results the tool returned');
});
