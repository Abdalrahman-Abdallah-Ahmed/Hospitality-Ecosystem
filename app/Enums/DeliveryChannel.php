<?php

namespace App\Enums;

/**
 * How a recommendation reached the guest.
 *
 * EMAIL is a person at the hotel emailing the guest — the staff outcome
 * endpoint accepts it. The system itself never sends email.
 */
enum DeliveryChannel: string
{
    case WHATSAPP = 'whatsapp';        // sent in a concierge reply
    case FACE_TO_FACE = 'face_to_face';
    case PHONE = 'phone';
    case EMAIL = 'email';

    /**
     * The channel a staff entry or a booking recorded as free text. Anything
     * unrecognised — "desk", or nothing at all — was a person at the hotel.
     */
    public static function fromRecorded(?string $channel): self
    {
        return self::tryFrom((string) $channel) ?? self::FACE_TO_FACE;
    }
}
