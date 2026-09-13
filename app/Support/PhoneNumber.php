<?php

namespace App\Support;

/**
 * The one form phone numbers are stored and compared in: digits only, country
 * code included, no "+" — exactly what Meta's WhatsApp webhook sends. So
 * "+20 115 179 3758", "20-115-179-3758" and "201151793758" are one number.
 *
 * Best-effort, not E.164 parsing: a number typed without its country code
 * ("01151793758") stays a different number. Good enough for conservative
 * exact matching; not a substitute for a phone-number library.
 */
class PhoneNumber
{
    /** The shortest full international numbers in use. */
    public const MIN_DIGITS = 7;

    /** E.164's ceiling for a full number, country code included. */
    public const MAX_DIGITS = 15;

    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits !== '' ? $digits : null;
    }
}
