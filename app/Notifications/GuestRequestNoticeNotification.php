<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email form of a guest notice (SPEC-007), sent when WhatsApp rules forbid
 * the message. Not queued: SendGuestRequestNoticeJob already runs on the queue
 * and records the outcome. Same fixed text as the WhatsApp message; no staff
 * names, task titles or descriptions.
 */
class GuestRequestNoticeNotification extends Notification
{
    /**
     * @param  array<string, string>  $replacements
     */
    public function __construct(
        public string $messageKey,
        public string $language,
        public array $replacements,
        public string $text,
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
        return (new MailMessage)
            ->from(config('mail.from.address'), $this->replacements['hotel'])
            ->subject(__("guest_notices.email_subject_{$this->messageKey}", $this->replacements, $this->language))
            ->line($this->text);
    }
}
