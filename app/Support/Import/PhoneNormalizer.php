<?php

namespace App\Support\Import;

use App\Support\PhoneNumber;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Turns a phone number as a legacy export wrote it into the stored form
 * (PhoneNumber: digits with the country code, no "+"), using the hotel's
 * country for numbers written without one. "0020 100 123 4567",
 * "+20 100 123 4567" and, at an Egyptian hotel, "01001234567" are one number.
 *
 * Trunk prefixes differ by country (Italy keeps its leading 0), so this
 * parses with libphonenumber instead of swapping a leading 0 for a calling
 * code. Only the import uses it: PhoneNumber::digits() and everything that
 * compares stored numbers stay as they are.
 */
class PhoneNormalizer
{
    /** A national number at a hotel with no country code: kept as written. */
    public const WARNING_COUNTRY_UNKNOWN = 'phone_country_unknown';

    /** Not a valid number, even read with the hotel's country: kept as written. */
    public const WARNING_UNRECOGNIZED = 'phone_unrecognized';

    /**
     * @return array{digits: ?string, warning: ?string}
     */
    public static function normalize(?string $raw, ?string $countryCode): array
    {
        $value = trim(Digits::ascii((string) $raw));
        $digits = PhoneNumber::digits($value);
        if ($digits === null) {
            return ['digits' => null, 'warning' => null];
        }

        // "00" is the international prefix in most of the world; read it as
        // "+" so the number parses without knowing the caller's region.
        $international = str_starts_with($value, '+') || str_starts_with($digits, '00');
        if (! str_starts_with($value, '+') && str_starts_with($digits, '00')) {
            $value = '+'.substr($digits, 2);
        }

        $region = $countryCode ? strtoupper($countryCode) : null;

        if (($international || $region) && ($e164 = self::e164($value, $international ? null : $region))) {
            return ['digits' => $e164, 'warning' => null];
        }

        $kept = PhoneNumber::digits($value);

        return ['digits' => $kept, 'warning' => match (true) {
            $region !== null => self::WARNING_UNRECOGNIZED,
            ! $international && str_starts_with($kept, '0') => self::WARNING_COUNTRY_UNKNOWN,
            default => null,
        }];
    }

    /**
     * The number as staff at a hotel in `$countryCode` would type it without
     * the country code ("201001234567" → "01001234567" in Egypt), or null
     * when it is not a valid number of that country. Guests stored before
     * the import normalized phones may hold this form.
     */
    public static function nationalDigits(string $digits, ?string $countryCode): ?string
    {
        if (! $countryCode) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse('+'.$digits);
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumber($number) || $util->getRegionCodeForNumber($number) !== strtoupper($countryCode)) {
            return null;
        }

        $national = PhoneNumber::digits($util->format($number, PhoneNumberFormat::NATIONAL));

        return $national !== $digits ? $national : null;
    }

    private static function e164(string $value, ?string $region): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($value, $region);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? ltrim($util->format($number, PhoneNumberFormat::E164), '+') : null;
    }
}
