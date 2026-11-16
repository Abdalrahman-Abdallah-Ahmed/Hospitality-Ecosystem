<?php

use App\Enums\GuestNoticeChannel;
use App\Enums\GuestNoticeReason;
use App\Enums\GuestNoticeStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Notifications\GuestRequestNoticeNotification;
use App\Services\BookingCancellationService;
use App\Support\Audit\EventLogger;
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
 * A guest's booking on a sunset cruise in two days, which they asked the
 * Concierge to cancel. They wrote 2 hours ago, so the window is open.
 *
 * @return array{0: User, 1: Hotel, 2: Booking, 3: Task}
 */
function gsCancellationRequested(array $guestAttributes = []): array
{
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel, $guestAttributes);
    $activity = abActivity($hotel, ['name' => 'Sunset cruise', 'daily_capacity' => 6]);
    $booking = abBook($hotel, $activity, now()->addDays(2)->toDateString(), 2, ['guest_id' => $guest->id]);

    if ($guest->phone_number) {
        gsInbound(PhoneNumber::digits($guest->phone_number), now()->subHours(2));
    }

    ['task' => $task] = EventLogger::asAiAgent(fn () => app(BookingCancellationService::class)->request($booking, $guest, 'Flight changed'));

    return [$admin, $hotel, $booking, $task];
}

it('tells the guest the booking is cancelled when staff approve the request', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, $hotel, $booking, $task] = gsCancellationRequested();

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/approve")->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toContain('cancelled')
        ->toContain('Sunset cruise')
        ->toContain($booking->reference)
        ->toContain($booking->scheduled_date->toDateString())
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::WHATSAPP);
});

it('tells the guest the booking stands, with the staff note, when staff decline', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , $booking, $task] = gsCancellationRequested();

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/decline", [
        'note' => 'The boat is fully paid by your tour operator',
    ])->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toContain('still in place')
        ->toContain('The boat is fully paid by your tour operator')
        ->and($task->fresh()->guest_notice_status)->toBe(GuestNoticeStatus::SENT);
});

it('sends the approved notice when staff cancel the booking directly while a request is open', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , $booking] = gsCancellationRequested();

    fdPost($this, $admin, "/api/booking/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'At the desk'])->assertOk();

    expect($whatsApp->sent)->toHaveCount(1)
        ->and($whatsApp->sent[0]['text'])->toContain('cancelled');
});

it('emails the outcome when the window has closed', function () {
    $whatsApp = gsFakeWhatsApp();
    [$admin, , $booking, $task] = gsCancellationRequested();
    WhatsAppInboundMessage::query()->update(['created_at' => now()->subHours(30)]);

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'Non-refundable'])->assertOk();

    expect($whatsApp->attempts)->toBe(0)
        ->and($task->fresh()->guest_notice_channel)->toBe(GuestNoticeChannel::EMAIL);
    Notification::assertSentOnDemand(GuestRequestNoticeNotification::class,
        fn ($notification) => str_contains($notification->text, 'Non-refundable'));
});

it('records no_contact for a guest with neither phone nor email', function () {
    gsFakeWhatsApp();
    [$admin, , $booking, $task] = gsCancellationRequested(['phone_number' => null, 'email' => null]);

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/approve")->assertOk();

    $task->refresh();
    expect($task->guest_notice_status)->toBe(GuestNoticeStatus::SKIPPED)
        ->and($task->guest_notice_reason)->toBe(GuestNoticeReason::NO_CONTACT);
});
