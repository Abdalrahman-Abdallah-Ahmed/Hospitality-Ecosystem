<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a hotel's admins when a reservation is created through WhatsApp,
 * so a booking nobody typed into the system still gets a human's eyes.
 */
class WhatsAppReservationCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * A reservation deleted before the queue gets to it has nothing left to
     * announce.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Reservation $reservation)
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
        $reservation = $this->reservation;
        $guest = $reservation->guest;

        $mail = (new MailMessage)
            ->subject("New WhatsApp reservation {$reservation->reservation_id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A reservation was created through WhatsApp at {$reservation->hotel->name}.")
            ->line("**Reservation:** {$reservation->reservation_id}")
            ->line('**Guest:** '.trim("{$guest->first_name} {$guest->last_name} ({$guest->phone_number})"))
            ->line('**Stay:** '.$reservation->arrival_date->format('Y-m-d').' to '.$reservation->departure_date->format('Y-m-d'))
            ->line("**Guests:** {$reservation->adults} adults, {$reservation->children} children")
            ->line('**Status:** '.ucfirst($reservation->status->value));

        if ($reservation->room) {
            $mail->line("**Room:** {$reservation->room->room_number}");
        }

        if ($reservation->special_requests) {
            $mail->line("**Special requests:** {$reservation->special_requests}");
        }

        return $mail;
    }
}
