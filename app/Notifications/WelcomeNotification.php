<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the admin who just registered an account and its first hotel.
 *
 * Queued so registration does not wait on the mail provider, and so a mail
 * outage cannot fail a registration that has already been committed.
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The hotel name is passed as a plain string rather than the model: the
     * job runs without a tenant context, and the email only needs the name.
     */
    public function __construct(public string $hotelName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to '.config('app.name'))
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("Your account and your hotel, {$this->hotelName}, are ready.")
            ->line('Sign in to add your rooms, invite your staff and set up your concierge.')
            ->line('If you did not create this account, you can ignore this email.');
    }
}
