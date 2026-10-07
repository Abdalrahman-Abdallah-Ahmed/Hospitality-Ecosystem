<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Enums\CreatedBy;
use App\Enums\GuestNoticeChannel;
use App\Enums\GuestNoticeReason;
use App\Enums\GuestNoticeStatus;
use App\Enums\GuestSignal;
use App\Enums\TaskStatus;
use App\Jobs\SendGuestRequestNoticeJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Notifications\GuestRequestNoticeNotification;
use App\Services\WhatsAppMessageService;
use App\Support\PhoneNumber;
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

/**
 * An in-house guest who wrote 3 hours ago (window open), and a maintenance
 * request they filed through the Concierge.
 *
 * @return array{0: User, 1: Hotel, 2: Guest, 3: Task}
 */
function gsOpenRequest(array $guestAttributes = [], string $signal = 'maintenance'): array
{
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel, $guestAttributes);
    $reservation = gsInHouse($hotel, $guest);

    if ($guest->phone_number) {
        gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHours(3));
    }

    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), [
        'kind' => $signal === 'maintenance' ? 'maintenance_request' : 'service_request',
        'title' => 'Air conditioning not cooling (Ahmed from engineering)', 'description' => 'Blows warm air']);

    return [$admin, $hotel, $guest, Task::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->sole()];
}

it('sends one WhatsApp notice when staff complete a guest request inside the window', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel, $guest, $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk()->assertJsonPath('body.status', 'completed');

    $task->refresh();
    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['to'])->toBe(PhoneNumber::digits($guest->phone_number))
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SENT)
        ->and($task->guest_notice_channel)->toBe(GuestNoticeChannel::WHATSAPP)
        ->and($task->guest_notice_at)->not->toBeNull();
});

it('names the kind of request and the hotel, never the task title, description or staff', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel, , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $text = $whatsApp->sent[0]['text'];
    expect($text)->toContain('maintenance')->toContain($hotel->name)
        ->not->toContain('Ahmed')
        ->not->toContain('Air conditioning not cooling')
        ->not->toContain('Blows warm air')
        ->not->toContain('Maintenance team');
});

it('writes the notice in Arabic for an Arabic-speaking guest', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest(['preferred_language' => 'ar']);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent[0]['text'])->toMatch('/\p{Arabic}/u');
});

it('records a cancelled request as skipped and tells the guest nothing', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'cancelled')->assertOk();

    $task->refresh();
    expect($whatsApp->sent)->toBe([])
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SKIPPED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::CANCELLED);
});

it('notifies once even when the request is reopened and completed again', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'in_progress')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent)->toHaveCount(1);
});

it('sends nothing when an escalation, a booking follow-up or a staff task is completed', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour());

    gsRunTool(new EscalateToHumanTool($guest, $hotel, $reservation), ['reason' => 'Wants the manager']);
    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), [
        'title' => 'Help booking the cruise', 'description' => 'Keen on the sunset cruise', 'kind' => 'booking_follow_up',
    ]);
    Task::create(['hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'title' => 'Restock minibar', 'created_by' => CreatedBy::MANUAL]);

    foreach (Task::withoutGlobalScope('hotel')->get() as $task) {
        hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();
    }

    expect($whatsApp->sent)->toBe([])
        ->and(Task::withoutGlobalScope('hotel')->whereNotNull('guest_notice_status')->count())->toBe(0);
});

it('completes the task even when WhatsApp keeps failing', function () {
    gsFakeWhatsApp(failures: 10);
    [$admin, , , $task] = gsOpenRequest(['email' => null]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk()->assertJsonPath('body.status', 'completed');

    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETED);
});

it('notifies a guest cleaning request closed through the housekeeping flow', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour());

    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), [
        'title' => 'Clean my room', 'description' => 'Please', 'task_category_id' => $hotel->cleaning_task_category_id,
    ]);
    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->guest_signal)->toBe(GuestSignal::SERVICE_REQUEST)
        ->and($task->housekeeping_kind)->not->toBeNull();

    hkSetTaskStatus($this, $admin, $task, 'in_progress')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toContain('housekeeping');
});

it('sends inside the hotel tenant context, not unscoped', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel, , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent[0]['hotel_ids'])->toBe([$hotel->id]);
});

// Outside the window (User Story 2)

it('emails the notice when the guest last wrote more than 24 hours ago', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , $guest, $task] = gsOpenRequest();
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subHours(30)]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($whatsApp->attempts)->toBe(0)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SENT)
        ->and($task->guest_notice_channel)->toBe(GuestNoticeChannel::EMAIL);
    Notification::assertSentOnDemand(
        GuestRequestNoticeNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $guest->email
            && str_contains($notification->text, 'maintenance'),
    );
});

it('records no_contact when the window is closed and there is no email', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest(['email' => null]);
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subHours(30)]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($whatsApp->attempts)->toBe(0)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SKIPPED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::NO_CONTACT);
    Notification::assertSentOnDemandTimes(GuestRequestNoticeNotification::class, 0);
});

it('emails a guest with no phone number even while the window would be open', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest(['phone_number' => null]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->attempts)->toBe(0)
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::EMAIL);
});

it('treats a message 23 h 55 min old as outside the window', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest();
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subMinutes(23 * 60 + 55)]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->attempts)->toBe(0)
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::EMAIL);
});

it('counts a recent message the guest sent to another hotel on the shared number', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , $guest, $task] = gsOpenRequest();
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subHours(30)]);
    [, $otherHotel] = gsHotel();
    gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHour())->update(['hotel_id' => $otherHotel->id]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::WHATSAPP);
});

it('falls back to email after three failed WhatsApp attempts', function () {
    $whatsApp = gsFakeWhatsApp(failures: 3);
    [$admin, , , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($whatsApp->attempts)->toBe(3)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SENT)
        ->and($task->guest_notice_channel)->toBe(GuestNoticeChannel::EMAIL);
});

it('records send_failed when WhatsApp fails and there is no email', function () {
    gsFakeWhatsApp(failures: 3);
    [$admin, , , $task] = gsOpenRequest(['email' => null]);

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::COMPLETED)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::FAILED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::SEND_FAILED);
});

it('records send_failed when the email cannot be sent', function () {
    gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest();
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subHours(30)]);
    Notification::shouldReceive('route')->andThrow(new RuntimeException('SMTP down'));

    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::COMPLETED)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::FAILED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::SEND_FAILED);
});

it('tells the guest when a cancelled request is reopened and completed', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , , $task] = gsOpenRequest();

    hkSetTaskStatus($this, $admin, $task, 'cancelled')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'in_progress')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'in_progress')->assertOk();
    hkSetTaskStatus($this, $admin, $task, 'completed')->assertOk();

    $task->refresh();
    expect($whatsApp->sent)->toHaveCount(1)
        ->and($task->guest_notice_status)->toBe(GuestNoticeStatus::SENT)
        ->and($task->guest_notice_reason)->toBeNull();
});

// A worker that died mid-send

it('takes over a pending claim a dead worker left behind', function () {
    $whatsApp = gsFakeWhatsApp();
    [, , , $task] = gsOpenRequest();
    $task->forceFill(['status' => 'completed', 'guest_notice_status' => 'pending', 'guest_notice_at' => now()->subMinutes(10)])->saveQuietly();

    (new SendGuestRequestNoticeJob($task->id))->handle(app(WhatsAppMessageService::class));

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($task->fresh()->guest_notice_status)->toBe(GuestNoticeStatus::SENT);
});

it('leaves a claim another worker is still sending alone', function () {
    $whatsApp = gsFakeWhatsApp();
    [, , , $task] = gsOpenRequest();
    $task->forceFill(['status' => 'completed', 'guest_notice_status' => 'pending', 'guest_notice_at' => now()->subMinute()])->saveQuietly();

    (new SendGuestRequestNoticeJob($task->id))->handle(app(WhatsAppMessageService::class));

    expect($whatsApp->sent)->toBe([])
        ->and($task->fresh()->guest_notice_status)->toBe(GuestNoticeStatus::PENDING);
});

it('records the guest as not reached when the job fails for good', function () {
    [, , , $task] = gsOpenRequest();
    $task->forceFill(['status' => 'completed', 'guest_notice_status' => 'pending', 'guest_notice_at' => now()])->saveQuietly();

    (new SendGuestRequestNoticeJob($task->id))->failed(new RuntimeException('Worker timed out'));

    $task->refresh();
    expect($task->guest_notice_status)->toBe(GuestNoticeStatus::FAILED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::SEND_FAILED);
});
