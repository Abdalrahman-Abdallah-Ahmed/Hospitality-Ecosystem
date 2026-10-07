<?php

namespace App\Enums;

/**
 * How a guest notice went out: WhatsApp inside the 24-hour window, email when
 * WhatsApp rules forbid the message (constitution v2.1.0).
 */
enum GuestNoticeChannel: string
{
    case WHATSAPP = 'whatsapp';
    case EMAIL = 'email';
}
