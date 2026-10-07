<?php

use App\Ai\Tools\RequestRoomChangeTool;
use App\Enums\GuestNoticeChannel;
use App\Enums\GuestSignal;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
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

it('files an in-house guest room change for their stay, with no team, and emails the admins', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214']);
    $stay = Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole();
    $turn = new PitchTurn(null, eligible: true);

    $result = gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation, $turn), [
        'reason' => 'Too noisy at night', 'preference' => 'higher floor',
    ]);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($result)->toContain('Room-change request passed to staff')->toContain('do not promise a move')
        ->and($task->guest_signal)->toBe(GuestSignal::ROOM_CHANGE_REQUEST)
        ->and($task->stay_id)->toBe($stay->id)
        ->and($task->room_id)->toBe($stay->room_id)
        ->and($task->assigned_to_team_id)->toBeNull()
        ->and($task->task_category_id)->toBeNull()
        ->and($task->description)->toContain('Too noisy at night')->toContain('higher floor')
        ->and($turn->mayPitch())->toBeFalse();
    Notification::assertSentTo(User::find($admin->id), AiTaskCreatedNotification::class);
});

it('never changes the guest room assignment', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214']);
    $room = Room::withoutGlobalScope('hotel')->where('room_number', '214')->sole();

    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'View is blocked']);

    expect(Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole()->room_id)->toBe($room->id)
        ->and(ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole()->room_id)->toBe($room->id);
});

it('keeps one open room-change request per stay', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);

    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Noisy']);
    $second = gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Still noisy']);

    expect($second)->toContain('already with staff')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('files a request against the reservation before the guest arrives', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsUpcoming($hotel, $guest);

    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Would like a sea view']);

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->reservation_id)->toBe($reservation->id)
        ->and($task->stay_id)->toBeNull();
});

it('refuses a guest with only a past stay', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);

    $result = gsRunTool(new RequestRoomChangeTool($guest, $hotel, gsPast($hotel, $guest)), ['reason' => 'Bigger room']);

    expect($result)->toContain('needs a current or upcoming reservation')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('links the room the guest named when they are in several', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214', '215']);

    $ask = gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Smells of smoke']);
    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Smells of smoke', 'room_number' => '215']);

    expect($ask)->toContain('Ask which room')
        ->and(Task::withoutGlobalScope('hotel')->sole()->room->room_number)->toBe('215');
});

it('tells the guest when staff complete the request', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour());

    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $reservation), ['reason' => 'Noisy']);
    $task = Task::withoutGlobalScope('hotel')->sole();
    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toContain('room change')
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::WHATSAPP);
});
