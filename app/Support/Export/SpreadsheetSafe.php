<?php

namespace App\Support\Export;

/**
 * Keeps text typed by guests or staff from running as a formula when an
 * exported CSV is opened in a spreadsheet program (spec 021 FR-011, OWASP
 * "CSV injection"). Excel files store text cells explicitly instead, so only
 * CSV needs the prefix.
 *
 * A leading `+` or `-` only triggers a formula when something other than a
 * number follows, so phone numbers (+20 123 456 7890) and negative amounts
 * are left as they are.
 */
class SpreadsheetSafe
{
    private const ALWAYS = ['=', '@', "\t", "\r"];

    private const UNLESS_NUMERIC = ['+', '-'];

    private const NUMERIC = '/^[+-][\d\s().\/-]*$/';

    public static function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $first = $value[0];

        if (in_array($first, self::ALWAYS, true)
            || (in_array($first, self::UNLESS_NUMERIC, true) && ! preg_match(self::NUMERIC, $value))) {
            return "'".$value;
        }

        return $value;
    }
}
