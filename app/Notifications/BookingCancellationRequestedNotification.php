<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a hotel's admins when a guest asks the Concierge to cancel a
 * booking (SPEC-043). The Concierge never cancels one itself, so the request
 * waits for staff in the cancellation queue until someone answers it.
 */
class BookingCancellationRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * A request deleted before the queue gets to it has nothing left to announce.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Task $task)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->task->booking()->withoutGlobalScope('hotel')->first();
        $guest = $this->task->guest()->withoutGlobalScope('hotel')->first();
        $when = trim(($booking?->scheduled_date?->toDateString() ?? '').' '.substr((string) $booking?->scheduled_time, 0, 5));

        return (new MailMessage)
            ->subject("Cancellation requested: {$booking?->item_name} ({$booking?->reference})")
            ->greeting("Hello {$notifiable->name},")
            ->line('A guest has asked to cancel a booking. It stays as it is until someone approves or declines the request.')
            ->line('Guest: '.trim(($guest?->first_name ?? '').' '.($guest?->last_name ?? '')))
            ->line("Booking: {$booking?->item_name}, reference {$booking?->reference}")
            ->line('When: '.($when !== '' ? $when : 'not scheduled'))
            ->line("Party: {$booking?->pax}")
            ->line('Reason given: '.($this->task->description ?: 'none'));
    }
}
