<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\InsightsAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\GetGuestMessagesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * SPEC-082 FR-033: guest message text is only in the dashboard's
 * conversation viewer. The Admin AI cannot read it for anyone; the
 * background agents still read it to find themes, but never quote it.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('gives the Admin AI no tool that reads guest messages, not even for an admin', function () {
    $hotel = cmaHotel();

    $inner = collect(AdminToolset::for(cmaAdmin($hotel)))
        ->map(fn (GuardedTool $tool) => $tool->inner()::class);

    expect($inner)->not->toContain(GetGuestMessagesTool::class);
});

it('tells the Admin AI to send people to the conversation viewer', function () {
    $instructions = (string) (new AdminAdvisorAgent(cmaAdmin(cmaHotel())))->instructions();

    expect($instructions)->toContain('You cannot read or show guest messages')
        ->toContain('conversation viewer');
});

it('keeps guest messages for the background agents, which must not quote them', function () {
    $hotel = cmaHotel();
    [, $reservation] = rapInHouseStay($hotel);

    $insights = new InsightsAgent(cmaAdmin($hotel));
    $recommendation = new RecommendationAgent($hotel, $reservation);

    foreach ([$insights, $recommendation] as $agent) {
        expect(collect($agent->tools())->map(fn ($tool) => $tool::class))->toContain(GetGuestMessagesTool::class)
            ->and((string) $agent->instructions())->toContain("never quote a guest's message word for word");
    }
});
