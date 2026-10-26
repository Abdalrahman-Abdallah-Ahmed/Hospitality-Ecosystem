<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the platform owner something needs attention: a lane waiting past
 * its limit, a job failing repeatedly, an AI spend ceiling reached.
 *
 * Deliberately not queued: an alert about a stuck lane must not wait in
 * that lane. Callers pass only operational facts — never a job payload,
 * guest contact details or message text.
 */
class PlatformAlertNotification extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
