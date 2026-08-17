<?php

namespace App\Support\Recommendations;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Support\Str;

/**
 * Recommendations link to a conversation, but nothing in this app creates
 * one ahead of time — this finds the reservation's open conversation, or
 * starts one, so a recommendation always has somewhere to attach.
 */
class ConversationResolver
{
    public static function forReservation(Reservation $reservation): Conversation
    {
        return Conversation::firstOrCreate(
            [
                'reservation_id' => $reservation->id,
                'status' => ConversationStatus::OPEN,
            ],
            [
                'id' => (string) Str::uuid(),
                'sender_id' => $reservation->guest_id,
                'sender_type' => Guest::class,
                'hotel_id' => $reservation->hotel_id,
                'channel' => ConversationChannel::WHATSAPP,
                'started_at' => now(),
            ]
        );
    }
}
