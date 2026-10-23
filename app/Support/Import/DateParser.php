<?php

namespace App\Support\Import;

use App\Enums\ImportDateOrder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

/**
 * Reads the dates legacy exports write, and nothing else (FR-008: an
 * unparseable date is invalid, never guessed). Accepted:
 *
 * - spreadsheet date cells (Excel serial numbers);
 * - `2025-09-12` (year first is never ambiguous);
 * - `12/09/2025`, `12-09-25`, `12.09.2025`: day and month in the order the
 *   uploader chose for the file;
 * - `12 Sep 2025`, `12-Sep-2025`, `Sep 12, 2025`: English month names.
 *
 * A time part is ignored. Two-digit years are 20yy. Impossible dates such as
 * 31/02/2025 are rejected rather than rolled into March.
 */
class DateParser
{
    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    /** Serial numbers this far apart cover 1900–2173: anything else is not a date cell. */
    private const MAX_SERIAL = 100000;

    public static function parse(mixed $raw, ImportDateOrder $order): ?CarbonImmutable
    {
        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw)->startOfDay();
        }

        if (is_int($raw) || is_float($raw)) {
            return self::fromSerial((float) $raw);
        }

        $value = trim(Digits::ascii((string) $raw));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            return self::fromSerial((float) $value);
        }

        $value = self::withoutTime($value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $value, $m)) {
            [$day, $month] = $order === ImportDateOrder::DAY_FIRST ? [$m[1], $m[2]] : [$m[2], $m[1]];

            return self::make(self::year($m[3]), (int) $month, (int) $day);
        }

        if (preg_match('/^(\d{1,2})[\s\-\/.]+([a-z]+)\.?[\s\-\/.,]+(\d{2}|\d{4})$/i', $value, $m)) {
            return self::named($m[3], $m[2], $m[1]);
        }

        if (preg_match('/^([a-z]+)\.?[\s\-\/.]+(\d{1,2}),?[\s\-\/.,]+(\d{2}|\d{4})$/i', $value, $m)) {
            return self::named($m[3], $m[1], $m[2]);
        }

        return null;
    }

    private static function withoutTime(string $value): string
    {
        // ISO "2025-09-12T14:00:00Z", or a trailing "14:00", "2:00 PM".
        $value = preg_replace('/T\d{1,2}:\d{2}.*$/i', '', $value);

        return trim(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[ap]\.?m\.?)?$/i', '', $value));
    }

    private static function fromSerial(float $serial): ?CarbonImmutable
    {
        if ($serial < 1 || $serial >= self::MAX_SERIAL) {
            return null;
        }

        try {
            return CarbonImmutable::instance(SpreadsheetDate::excelToDateTimeObject($serial))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private static function named(string $year, string $monthName, string $day): ?CarbonImmutable
    {
        $month = self::MONTHS[strtolower($monthName)] ?? null;

        return $month ? self::make(self::year($year), $month, (int) $day) : null;
    }

    private static function year(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
