<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Support\Pitching\PitchTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
});

function gsServiceTool($hotel, $guest, $reservation): CreateGuestServiceRequestTool
{
    return new CreateGuestServiceRequestTool($guest, $hotel, $reservation);
}

function gsMaintenanceTool($hotel, $guest, $reservation): CreateGuestServiceRequestTool
{
    return new CreateGuestServiceRequestTool($guest, $hotel, $reservation);
}

it('files an amenities request under a housekeeping category and its team', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    $amenities = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->housekeeping_team_id, 'name' => 'Amenities']);

    gsRunTool(gsServiceTool($hotel, $guest, $reservation), [
        'title' => 'Extra towels', 'description' => 'Two extra towels please', 'task_category_id' => $amenities->id,
    ]);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->guest_signal)->toBe(GuestSignal::SERVICE_REQUEST)
        ->and($task->task_category_id)->toBe($amenities->id)
        ->and($task->assigned_to_team_id)->toBe($hotel->housekeeping_team_id);
});

it('files a maintenance request under the hotel maintenance category and team, for the guest room', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214']);
    $room = Room::withoutGlobalScope('hotel')->where('room_number', '214')->sole();
    $stay = Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole();

    $result = gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request',
        'title' => 'Air conditioning not cooling', 'description' => 'The AC blows warm air',
    ]);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($result)->toContain('Maintenance request filed for room 214')
        ->and($task->guest_signal)->toBe(GuestSignal::MAINTENANCE_REQUEST)
        ->and($task->task_category_id)->toBe($hotel->maintenance_task_category_id)
        ->and($task->assigned_to_team_id)->toBe($hotel->maintenance_team_id)
        ->and($task->stay_id)->toBe($stay->id)
        ->and($task->room_id)->toBe($room->id)
        ->and($room->fresh()->status->value)->toBe('occupied');
});

it('files a request no category fits with no category and no team', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(gsServiceTool($hotel, $guest, $reservation), ['title' => 'Taxi to the airport', 'description' => 'At 6am tomorrow']);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->task_category_id)->toBeNull()
        ->and($task->assigned_to_team_id)->toBeNull();
});

it('ignores a category from another hotel or one that was deleted', function () {
    [, $hotel] = gsHotel();
    [, $other] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    $deleted = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->housekeeping_team_id, 'name' => 'Old']);
    $deleted->delete();

    foreach ([$other->cleaning_task_category_id, $deleted->id] as $categoryId) {
        gsRunTool(gsServiceTool($hotel, $guest, $reservation), [
            'title' => 'Pillows', 'description' => 'More pillows', 'task_category_id' => $categoryId,
        ]);
    }

    expect(Task::withoutGlobalScope('hotel')->whereNotNull('task_category_id')->count())->toBe(0)
        ->and(Task::withoutGlobalScope('hotel')->whereNotNull('assigned_to_team_id')->count())->toBe(0);
});

it('asks which room before filing when the guest is in several rooms and gave none', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214', '215']);

    $result = gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request', 'title' => 'Leak', 'description' => 'Bathroom leak']);

    expect($result)->toContain('214')->toContain('215')->toContain('Ask which room')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(0);

    gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request', 'title' => 'Leak', 'description' => 'Bathroom leak', 'room_number' => '215']);

    expect(Task::withoutGlobalScope('hotel')->sole()->room->room_number)->toBe('215');
});

it('adds detail to the guest own open request of the same kind instead of filing another', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request', 'title' => 'AC broken', 'description' => 'Warm air']);
    $open = Task::withoutGlobalScope('hotel')->sole();

    $result = gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request',
        'title' => 'AC still broken', 'description' => 'Now it is making a noise too', 'add_to_request_id' => $open->id,
    ]);

    expect($result)->toContain('Added to your existing request')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1)
        ->and($open->fresh()->description)->toContain('Warm air')->toContain('Now it is making a noise too');
});

it('files a new request when add_to_request_id is another guest request or another kind', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $other = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214']);
    $otherReservation = gsInHouse($hotel, $other, ['301']);

    gsRunTool(gsMaintenanceTool($hotel, $other, $otherReservation), ['kind' => 'maintenance_request', 'title' => 'Leak', 'description' => 'Sink leak']);
    $theirs = Task::withoutGlobalScope('hotel')->sole();
    gsRunTool(gsServiceTool($hotel, $guest, $reservation), ['title' => 'Towels', 'description' => 'Two towels']);
    $mineServiceRequest = Task::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->sole();

    gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request',
        'title' => 'Lamp broken', 'description' => 'Desk lamp', 'add_to_request_id' => $theirs->id,
    ]);
    gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request',
        'title' => 'Shower broken', 'description' => 'No hot water', 'add_to_request_id' => $mineServiceRequest->id,
    ]);

    expect(Task::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->where('guest_signal', GuestSignal::MAINTENANCE_REQUEST->value)->count())->toBe(2)
        ->and($theirs->fresh()->description)->toBe('Sink leak')
        ->and($mineServiceRequest->fresh()->description)->toBe('Two towels');
});

it('keeps the one-open-clean rule for a cleaning request', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    $args = ['title' => 'Clean my room', 'description' => 'Please clean', 'task_category_id' => $hotel->cleaning_task_category_id];
    gsRunTool(gsServiceTool($hotel, $guest, $reservation), $args);
    $second = gsRunTool(gsServiceTool($hotel, $guest, $reservation), $args);

    expect($second)->toContain('already scheduled')
        ->and(Task::withoutGlobalScope('hotel')->where('housekeeping_kind', HousekeepingKind::CLEANING->value)->count())->toBe(1);
});

it('keeps pitching quiet when the guest adds detail to an open request', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsRunTool(gsMaintenanceTool($hotel, $guest, $reservation), ['kind' => 'maintenance_request', 'title' => 'AC broken', 'description' => 'Warm air']);
    gsRunTool(gsServiceTool($hotel, $guest, $reservation), ['title' => 'Towels', 'description' => 'Two towels']);
    $maintenance = Task::withoutGlobalScope('hotel')->where('guest_signal', GuestSignal::MAINTENANCE_REQUEST->value)->sole();
    $service = Task::withoutGlobalScope('hotel')->where('guest_signal', GuestSignal::SERVICE_REQUEST->value)->sole();

    $maintenanceTurn = new PitchTurn(null, eligible: true);
    $serviceTurn = new PitchTurn(null, eligible: true);
    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation, $maintenanceTurn), ['kind' => 'maintenance_request',
        'title' => 'AC still broken', 'description' => 'Still warm', 'add_to_request_id' => $maintenance->id,
    ]);
    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation, $serviceTurn), [
        'title' => 'Towels', 'description' => 'Still waiting', 'add_to_request_id' => $service->id,
    ]);

    expect($maintenanceTurn->mayPitch())->toBeFalse()
        ->and($serviceTurn->mayPitch())->toBeFalse()
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(2);
});

it('routes a maintenance request to the maintenance team even when the model names another category', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(gsServiceTool($hotel, $guest, $reservation), [
        'kind' => 'maintenance_request', 'title' => 'Shower broken', 'description' => 'No hot water',
        'task_category_id' => $hotel->cleaning_task_category_id,
    ]);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->guest_signal)->toBe(GuestSignal::MAINTENANCE_REQUEST)
        ->and($task->task_category_id)->toBe($hotel->maintenance_task_category_id)
        ->and($task->assigned_to_team_id)->toBe($hotel->maintenance_team_id)
        ->and($task->housekeeping_kind)->toBeNull();
});

it('files a new request when the model passes an id that is not a uuid', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    $result = gsRunTool(gsServiceTool($hotel, $guest, $reservation), [
        'kind' => 'maintenance_request', 'title' => 'AC broken', 'description' => 'Warm air', 'add_to_request_id' => '1',
    ]);

    expect($result)->toContain('Maintenance request filed')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1);
});
