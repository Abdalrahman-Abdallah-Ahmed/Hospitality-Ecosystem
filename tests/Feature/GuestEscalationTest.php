<?php

use App\Ai\Tools\EscalateToHumanTool;
use App\Ai\Tools\RequestRoomChangeTool;
use App\Enums\GuestSignal;
use App\Enums\Priority;
use App\Models\EventLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Support\PhoneNumber;
use App\Support\Pitching\PitchTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
    Sleep::fake();
});

it('creates a high-priority escalation for the active reservation and emails the admins', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    $turn = new PitchTurn(null, eligible: true);

    $result = gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation, $turn), ['reason' => 'Wants to speak to the manager']);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($result)->toContain('A staff member has been notified')
        ->and($task->guest_signal)->toBe(GuestSignal::ESCALATION)
        ->and($task->priority)->toBe(Priority::HIGH)
        ->and($task->description)->toBe('Wants to speak to the manager')
        ->and($task->reservation_id)->toBe($reservation->id)
        ->and($turn->mayPitch())->toBeFalse();
    Notification::assertSentTo(User::find($admin->id), AiTaskCreatedNotification::class);
});

it('adds a repeated ask to the open escalation without a new task or a new email', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'Room too cold']);
    $turn = new PitchTurn(null, eligible: true);
    $second = gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation, $turn), ['reason' => 'Still nobody came']);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($second)->toContain('Staff already have')
        ->and($task->description)->toContain('Room too cold')->toContain('Still nobody came')
        ->and($turn->mayPitch())->toBeFalse()
        ->and(EventLog::where('subject_id', $task->id)->where('event_type', 'like', '%escalation_repeated')->exists())->toBeTrue();
    Notification::assertSentToTimes(User::find($admin->id), AiTaskCreatedNotification::class, 1);
});

it('opens a new escalation once the previous one is closed', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'First']);
    hkSetTaskStatus($this, $admin, Task::withoutGlobalScope('hotel')->sole(), 'completed')->assertOk();
    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'Second']);

    expect(Task::withoutGlobalScope('hotel')->count())->toBe(2);
});

it('lets a guest with only a past stay escalate, without linking the old reservation', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $past = gsPast($hotel, $guest);

    $result = gsRunTool(new EscalateToHumanTool($guest, $hotel, $past), ['reason' => 'Lost property from last visit']);

    expect($result)->toContain('A staff member has been notified')
        ->and(Task::withoutGlobalScope('hotel')->sole()->reservation_id)->toBeNull();
});

it('sends the guest no notice when staff close the escalation', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour());

    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'Help']);
    hkSetTaskStatus($this, $admin, Task::withoutGlobalScope('hotel')->sole(), 'completed')->assertOk();

    expect($whatsApp->sent)->toBe([])
        ->and(Task::withoutGlobalScope('hotel')->sole()->guest_notice_status)->toBeNull();
});

it('refuses with 422, not 500, to reopen an escalation while the guest has another open', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'First']);
    $first = Task::withoutGlobalScope('hotel')->sole();
    hkSetTaskStatus($this, $admin, $first, 'completed')->assertOk();
    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'Second']);

    hkSetTaskStatus($this, $admin, $first, 'pending')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This guest already has another open escalation. Close it, or add to it, before reopening this one.');

    expect($first->fresh()->status->value)->toBe('completed');
});

it('refuses with 422 to reopen a room-change request while the stay has another open', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Noisy']);
    $first = Task::withoutGlobalScope('hotel')->sole();
    hkSetTaskStatus($this, $admin, $first, 'cancelled')->assertOk();
    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Still noisy']);

    hkSetTaskStatus($this, $admin, $first, 'in_progress')->assertStatus(422);
});
