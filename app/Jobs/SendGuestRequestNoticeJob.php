<?php

namespace App\Jobs;

use App\Enums\CancellationResolution;
use App\Enums\GuestNoticeChannel;
use App\Enums\GuestNoticeReason;
use App\Enums\GuestNoticeStatus;
use App\Enums\GuestSignal;
use App\Enums\TaskStatus;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use App\Notifications\GuestRequestNoticeNotification;
use App\Services\WhatsAppMessageService;
use App\Support\Audit\EventLogger;
use App\Support\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a guest how their request ended (SPEC-007, R7–R11): a service,
 * maintenance or room-change request was completed, or staff decided a
 * booking cancellation request.
 *
 * - At most once per task: the job claims the task (`guest_notice_status`
 *   null → pending) before doing anything, so a reopened and re-completed
 *   request, or two workers, never send twice.
 * - WhatsApp inside the 24-hour window, measured per phone number on the
 *   shared line; email when WhatsApp rules forbid the message (constitution
 *   v2.1.0). Never a template, never AI-written.
 * - Each channel is retried in the job and every failure is recorded, never
 *   thrown: a notice must not block or undo staff completing the task.
 */
class SendGuestRequestNoticeJob implements ShouldQueue
{
    use Queueable;

    /**
     * WhatsApp's window is 24 hours; the margin covers queue delay and clock
     * skew so a message is never sent just after it closed.
     */
    public const WINDOW_MINUTES = 24 * 60 - 10;

    /** Pauses between a channel's three attempts, in milliseconds (retry() makes one attempt more than pauses). */
    public const RETRY_BACKOFF_MS = [1000, 3000];

    /**
     * A claim older than this was left by a worker that died mid-send (killed,
     * timed out): the next attempt, or a later completion, may take it over.
     * Longer than $timeout, so a claim still being worked on is never stolen.
     */
    public const STALE_CLAIM_MINUTES = 5;

    /**
     * Three WhatsApp attempts at the HTTP client's 30 s timeout, plus the
     * email fallback, fit well inside this; it stays below the queue's
     * retry_after (360 s) so no second worker picks the job up meanwhile.
     */
    public int $timeout = 240;

    /** A worker killed mid-send is retried; the stale claim is taken over. */
    public int $tries = 3;

    public function __construct(public string $taskId) {}

    public function handle(WhatsAppMessageService $whatsApp): void
    {
        $claimed = Task::withoutGlobalScope('hotel')
            ->whereKey($this->taskId)
            // Same rule as Task::awaitsGuestNotice(), checked atomically.
            ->where(fn ($query) => $query->whereNull('guest_notice_status')
                ->orWhere(fn ($query) => $query
                    ->where('guest_notice_status', GuestNoticeStatus::SKIPPED->value)
                    ->where('guest_notice_reason', GuestNoticeReason::CANCELLED->value)
                    ->where('status', TaskStatus::COMPLETED->value))
                // A claim abandoned by a dead worker.
                ->orWhere(fn ($query) => $query
                    ->where('guest_notice_status', GuestNoticeStatus::PENDING->value)
                    ->where('guest_notice_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->update([
                'guest_notice_status' => GuestNoticeStatus::PENDING->value,
                'guest_notice_reason' => null,
                // When the claim was taken; finish() overwrites it.
                'guest_notice_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        // The only unscoped read: the hotel is not known until the task is.
        $task = Task::withoutGlobalScope('hotel')->find($this->taskId);

        if (! $task) {
            return;
        }

        TenantContext::runForHotel($task->hotel_id, function () use ($task, $whatsApp): void {
            try {
                $this->notify($task, $whatsApp);
            } catch (Throwable $e) {
                report($e);
                $this->finish($task, GuestNoticeStatus::FAILED, reason: GuestNoticeReason::SEND_FAILED);
            }
        });
    }

    /**
     * The job died for good (timed out on its last try, or the worker kept
     * being killed): staff see the guest was not reached instead of a notice
     * stuck at pending.
     */
    public function failed(?Throwable $exception): void
    {
        $task = Task::withoutGlobalScope('hotel')
            ->whereKey($this->taskId)
            ->where('guest_notice_status', GuestNoticeStatus::PENDING->value)
            ->first();

        if ($task) {
            TenantContext::runForHotel($task->hotel_id, fn () => $this->finish($task, GuestNoticeStatus::FAILED, reason: GuestNoticeReason::SEND_FAILED));
        }
    }

    private function notify(Task $task, WhatsAppMessageService $whatsApp): void
    {
        $guest = Guest::find($task->guest_id);
        $hotel = Hotel::find($task->hotel_id);
        $key = $this->messageKey($task);

        if ($key === null || ! $guest || ! $hotel) {
            $this->finish($task, GuestNoticeStatus::SKIPPED, reason: GuestNoticeReason::CANCELLED);

            return;
        }

        $locale = $guest->preferred_language === 'ar' ? 'ar' : 'en';
        $replacements = $this->replacements($task, $guest, $hotel, $locale);
        $text = __("guest_notices.{$key}", $replacements, $locale);
        $phone = PhoneNumber::digits($guest->phone_number);
        $whatsAppFailed = false;

        if ($phone !== null && $this->windowOpen($phone)) {
            try {
                retry(self::RETRY_BACKOFF_MS, fn () => $whatsApp->send($phone, $text));
                $this->finish($task, GuestNoticeStatus::SENT, channel: GuestNoticeChannel::WHATSAPP);

                return;
            } catch (Throwable $e) {
                report($e);
                $whatsAppFailed = true; // falls back to email
            }
        }

        if (! $guest->email) {
            $whatsAppFailed
                ? $this->finish($task, GuestNoticeStatus::FAILED, reason: GuestNoticeReason::SEND_FAILED)
                : $this->finish($task, GuestNoticeStatus::SKIPPED, reason: GuestNoticeReason::NO_CONTACT);

            return;
        }

        try {
            retry(self::RETRY_BACKOFF_MS, fn () => Notification::route('mail', $guest->email)
                ->notify(new GuestRequestNoticeNotification($key, $locale, $replacements, $text)));
        } catch (Throwable $e) {
            report($e);
            $this->finish($task, GuestNoticeStatus::FAILED, reason: GuestNoticeReason::SEND_FAILED);

            return;
        }

        $this->finish($task, GuestNoticeStatus::SENT, channel: GuestNoticeChannel::EMAIL);
    }

    /**
     * Which message, or null when nothing is to be said: a cancelled
     * request, or a task that is not a guest request after all.
     */
    private function messageKey(Task $task): ?string
    {
        if ($task->guest_signal === GuestSignal::CANCELLATION_REQUEST && $task->status === TaskStatus::COMPLETED) {
            return match ($task->resolution) {
                CancellationResolution::APPROVED => 'cancellation_approved',
                CancellationResolution::DECLINED => 'cancellation_declined',
                default => null,
            };
        }

        return $task->guest_signal?->notifiesOnCompletion() && $task->status === TaskStatus::COMPLETED
            ? 'completed'
            : null;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(Task $task, Guest $guest, Hotel $hotel, string $locale): array
    {
        $replacements = [
            'first_name' => $guest->first_name ?: ($locale === 'ar' ? 'ضيفنا' : 'there'),
            'hotel' => $hotel->name,
            'kind' => __('guest_notices.kinds.'.$this->kindLabel($task, $hotel), [], $locale),
        ];

        if ($task->guest_signal === GuestSignal::CANCELLATION_REQUEST) {
            $booking = Booking::find($task->booking_id);

            $replacements += [
                'item' => (string) $booking?->item_name,
                'date' => (string) $booking?->scheduled_date?->toDateString(),
                'reference' => (string) $booking?->reference,
                'note' => trim((string) $task->resolution_note),
            ];
        }

        return $replacements;
    }

    /**
     * A fixed label for the kind of request. The task title is never used:
     * staff can edit it, so it could carry a staff name (FR-008).
     */
    private function kindLabel(Task $task, Hotel $hotel): string
    {
        return match ($task->guest_signal) {
            GuestSignal::MAINTENANCE_REQUEST => 'maintenance',
            GuestSignal::ROOM_CHANGE_REQUEST => 'room_change',
            GuestSignal::SERVICE_REQUEST => $task->assigned_to_team_id !== null && $task->assigned_to_team_id === $hotel->housekeeping_team_id
                ? 'housekeeping'
                : 'service',
            default => 'service',
        };
    }

    /**
     * Whether the guest wrote to the shared number recently enough for a
     * free-form WhatsApp message (R9). Any hotel counts: the window belongs to
     * the phone number, not to one hotel's guest record.
     */
    private function windowOpen(string $phoneDigits): bool
    {
        return WhatsAppInboundMessage::query()
            ->where('phone_number', $phoneDigits)
            ->where('created_at', '>', now()->subMinutes(self::WINDOW_MINUTES))
            ->exists();
    }

    private function finish(Task $task, GuestNoticeStatus $status, ?GuestNoticeChannel $channel = null, ?GuestNoticeReason $reason = null): void
    {
        $task->forceFill([
            'guest_notice_status' => $status,
            'guest_notice_channel' => $channel,
            'guest_notice_reason' => $reason,
            'guest_notice_at' => now(),
        ])->save();

        EventLogger::record($task, 'guest_notified', changes: array_filter([
            'status' => $status->value,
            'channel' => $channel?->value,
            'reason' => $reason?->value,
        ]));

        if ($status === GuestNoticeStatus::SENT) {
            $closedAt = $task->resolved_at ?? $task->completed_at;

            // SC-002: how long the guest waited after staff closed the request.
            Log::info('Guest request notice sent', [
                'task_id' => $task->id,
                'channel' => $channel?->value,
                'seconds_after_close' => $closedAt ? (int) $closedAt->diffInSeconds(now()) : null,
            ]);
        }
    }
}
