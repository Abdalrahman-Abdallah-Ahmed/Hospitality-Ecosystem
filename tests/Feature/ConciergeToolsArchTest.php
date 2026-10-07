<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\CheckInTool;
use App\Ai\Tools\CheckOutTool;
use App\Ai\Tools\CreateBookingTool;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Ai\Tools\GetActivitiesTool;
use App\Ai\Tools\GetGuestAvailabilityTool;
use App\Ai\Tools\GetOwnBookingsTool;
use App\Ai\Tools\GetOwnRequestsTool;
use App\Ai\Tools\GetOwnReservationTool;
use App\Ai\Tools\GetRecommendationsTool;
use App\Ai\Tools\GetTaskCategoriesTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Ai\Tools\RequestBookingCancellationTool;
use App\Ai\Tools\RequestRoomChangeTool;
use App\Ai\Tools\UpdateRecommendationTool;
use App\Http\Controllers\StayCheckInController;
use App\Http\Controllers\StayCheckOutController;
use App\Services\BookingService;
use App\Services\MaintenanceService;
use App\Services\StayLifecycleService;
use App\Support\Reservations\RoomAssignmentRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The guest limits are enforced by what the Concierge can call, not by its
| prompt (SPEC-007 FR-031, SC-004). This pins the guest tool set and keeps
| room, stay and booking-status code out of reach of every guest tool.
|
| When a story adds a guest tool, it adds it to GUEST_TOOLS in the same
| commit; the final set must match contracts/concierge-tools.md.
*/

uses(RefreshDatabase::class);

const GUEST_TOOLS = [
    GetOwnReservationTool::class,
    GetOwnBookingsTool::class,
    GetOwnRequestsTool::class,
    GetActivitiesTool::class,
    GetGuestAvailabilityTool::class,
    KnowledgeSearchTool::class,
    GetRecommendationsTool::class,
    GetTaskCategoriesTool::class,
    UpdateRecommendationTool::class,
    CreateBookingTool::class,
    RequestBookingCancellationTool::class,
    CreateGuestServiceRequestTool::class,
    RequestRoomChangeTool::class,
    EscalateToHumanTool::class,
];

it('gives the Concierge exactly the guest tool set', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $agent = new GuestConciergeAgent($guest, $hotel, gsInHouse($hotel, $guest));

    $tools = collect($agent->tools())->map(fn ($tool) => $tool::class)->sort()->values()->all();

    expect($tools)->toBe(collect(GUEST_TOOLS)->sort()->values()->all());
});

arch('guest tools never reach check-in, check-out or room assignment')
    ->expect(GUEST_TOOLS)
    ->not->toUse([
        StayLifecycleService::class,
        StayCheckInController::class,
        StayCheckOutController::class,
        CheckInTool::class,
        CheckOutTool::class,
        RoomAssignmentRules::class,
        MaintenanceService::class,
    ]);

arch('the cancellation-request tool only asks, it never touches the booking service')
    ->expect(RequestBookingCancellationTool::class)
    ->not->toUse(BookingService::class);

it('has no guest tool that changes a booking status', function () {
    foreach (GUEST_TOOLS as $tool) {
        $source = file_get_contents((new ReflectionClass($tool))->getFileName());

        expect($source)->not->toMatch('/->(cancel|updateStatus|realise|confirm|markNoShow)\(/', "{$tool} changes a booking status");
    }
});
