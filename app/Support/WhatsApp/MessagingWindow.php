<?php

namespace App\Support\WhatsApp;

use App\Models\WhatsAppInboundMessage;
use Carbon\CarbonInterface;

/**
 * WhatsApp's 24-hour customer-service window: after a person's last message,
 * the business may send them free-form messages for 24 hours; after that,
 * only pre-approved templates, which this platform does not use.
 *
 * Measured per phone number on the shared platform line (D12), so a message
 * to any hotel counts.
 */
final class MessagingWindow
{
    /**
     * The window is 24 hours; the margin covers queue delay and clock skew so
     * a message is never sent just after it closed.
     */
    public const MINUTES = 24 * 60 - 10;

    public static function isOpen(string $phoneDigits, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return WhatsAppInboundMessage::query()
            ->where('phone_number', $phoneDigits)
            ->where('created_at', '>', $at->copy()->subMinutes(self::MINUTES))
            ->where('created_at', '<=', $at)
            ->exists();
    }

    /**
     * When this number last wrote to the platform, if ever.
     */
    public static function lastInboundAt(string $phoneDigits): ?CarbonInterface
    {
        return WhatsAppInboundMessage::query()
            ->where('phone_number', $phoneDigits)
            ->latest('created_at')
            ->first()
            ?->created_at;
    }
}
