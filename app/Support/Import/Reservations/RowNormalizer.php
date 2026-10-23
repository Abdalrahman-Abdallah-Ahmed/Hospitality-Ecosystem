<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportDateOrder;
use App\Enums\ImportDecimalMark;
use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Support\Import\DateParser;
use App\Support\Import\Digits;
use App\Support\Import\PhoneNormalizer;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * Normalize stage: turns one row's raw cells into platform values. It never
 * guesses (FR-008): a value it cannot read is recorded as an error on its
 * column, and the row carries on so every problem is found in one pass.
 */
class RowNormalizer
{
    /** @var array<string, ReservationStatus>|null */
    private ?array $statusLabels = null;

    /**
     * @param  array<string, mixed>  $values  canonical column => raw cell
     */
    public function normalize(int $rowNumber, array $values, ImportDateOrder $order, Hotel $hotel, ImportDecimalMark $decimalMark = ImportDecimalMark::AUTO): NormalizedRow
    {
        $values = array_map(fn (mixed $value) => is_string($value) ? trim(Digits::ascii($value)) : $value, $values);

        $row = new NormalizedRow($rowNumber, ImportFileReader::isBlank($values));
        if ($row->blank) {
            return $row;
        }

        $row->reference = $this->text($values, 'reservation_reference');
        $row->arrival = $this->date($row, $values, 'arrival_date', $order);
        $row->departure = $this->date($row, $values, 'departure_date', $order);
        $this->status($row, $values);
        $row->source = $this->text($values, 'source');
        $row->adults = $this->wholeNumber($row, $values, 'adults', min: 1);
        $row->children = $this->wholeNumber($row, $values, 'children', min: 0);
        $row->childrenAges = $this->ages($row, $values);
        $row->specialRequests = $this->text($values, 'special_requests');
        $row->reservationValue = $this->money($row, $values, $decimalMark);
        $row->currency = $this->currency($row, $values);
        $row->roomNumber = $this->text($values, 'room_number');
        $row->roomType = $this->text($values, 'room_type');
        $row->floor = $this->text($values, 'floor');
        $row->roomCount = $this->wholeNumber($row, $values, 'room_count', min: 1);
        $row->guests = $this->guests($row, $values, $hotel);
        $row->activity = $this->activity($row, $values, $order);

        return $row;
    }

    /**
     * A legacy status label in the comparison form: lower-case, with spaces,
     * dashes and underscores folded to one space.
     */
    public static function statusKey(string $label): string
    {
        return trim(preg_replace('/[\s_\-]+/', ' ', mb_strtolower($label)));
    }

    /** @param array<string, mixed> $values */
    private function text(array $values, string $column): ?string
    {
        $value = $values[$column] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $values */
    private function date(NormalizedRow $row, array $values, string $column, ImportDateOrder $order): mixed
    {
        $raw = $values[$column] ?? null;
        if (blank($raw)) {
            return null;
        }

        $date = DateParser::parse($raw, $order);
        if (! $date) {
            $row->error($column, "\"{$raw}\" in {$column} is not a date this import can read. Use a format like 2025-09-12 or 12/09/2025.");
        }

        return $date;
    }

    /** @param array<string, mixed> $values */
    private function status(NormalizedRow $row, array $values): void
    {
        $raw = $this->text($values, 'status');
        $row->statusGiven = $raw !== null;
        $row->statusLabel = $raw;

        if ($raw === null) {
            $row->status = ReservationStatus::PENDING;

            return;
        }

        $status = $this->statusLabels()[self::statusKey($raw)] ?? null;
        if (! $status) {
            $row->error('status', "\"{$raw}\" is not a reservation status this import knows. Use confirmed, pending, cancelled, checked in, checked out or no show.");
        }

        $row->status = $status;
    }

    /** @return array<string, ReservationStatus> */
    private function statusLabels(): array
    {
        if ($this->statusLabels === null) {
            $this->statusLabels = [];
            foreach (config('reservation_import.status_labels', []) as $status => $labels) {
                foreach ([$status, ...$labels] as $label) {
                    $this->statusLabels[self::statusKey($label)] = ReservationStatus::from($status);
                }
            }
        }

        return $this->statusLabels;
    }

    /** @param array<string, mixed> $values */
    private function wholeNumber(NormalizedRow $row, array $values, string $column, int $min): ?int
    {
        $raw = $values[$column] ?? null;
        if (blank($raw)) {
            return null;
        }

        $value = is_numeric($raw) && (float) $raw == (int) $raw ? (int) $raw : null;
        if ($value === null || $value < $min) {
            $row->error($column, "{$column} must be a whole number of at least {$min}; the file has \"{$raw}\".");

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<int>|null
     */
    private function ages(NormalizedRow $row, array $values): ?array
    {
        $raw = $values['children_ages'] ?? null;
        if (blank($raw)) {
            return null;
        }

        $ages = [];
        foreach (preg_split('/[,;\/|]+/', (string) $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            if (! ctype_digit($item) || (int) $item > 17) {
                $row->error('children_ages', "children_ages must list whole-number ages from 0 to 17; the file has \"{$raw}\".");

                return null;
            }

            $ages[] = (int) $item;
        }

        return $ages;
    }

    /** @param array<string, mixed> $values */
    private function money(NormalizedRow $row, array $values, ImportDecimalMark $decimalMark): ?float
    {
        $raw = $values['reservation_value'] ?? null;
        if (blank($raw)) {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $amount = preg_replace('/[^\d.,\-]/', '', (string) $raw);

        if ($decimalMark !== ImportDecimalMark::AUTO) {
            $value = self::markedAmount($amount, $decimalMark);
            if ($value === null) {
                $row->error('reservation_value', "reservation_value \"{$raw}\" doesn't match the decimal mark chosen for this file ({$decimalMark->value}).");
            }

            return $value === null ? null : (float) $value;
        }

        $value = self::decimalAmount($amount);

        if ($value === null) {
            $row->error('reservation_value', "reservation_value \"{$raw}\" can be read more than one way. Write it without thousands separators, like 1200 or 1200.50.");

            return null;
        }

        if (! is_numeric($value)) {
            $row->error('reservation_value', "reservation_value must be an amount; the file has \"{$raw}\".");

            return null;
        }

        return (float) $value;
    }

    /**
     * An amount with "." as its decimal mark, or null when the separators
     * leave it ambiguous (FR-008: never guess). With both "," and ".", the
     * last one is the decimal mark ("1,200.50", "1.200,50"). One kind used
     * more than once separates thousands ("1.234.567"). One kind used once is
     * a decimal mark ("99,50"), unless exactly three digits follow it:
     * "1,200" and "1.200" mean 1200 in some countries and 1.2 in others.
     */
    private static function decimalAmount(string $value): ?string
    {
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');

        if ($comma !== false && $dot !== false) {
            [$decimal, $thousands] = $comma > $dot ? [',', '.'] : ['.', ','];

            return str_replace([$thousands, $decimal], ['', '.'], $value);
        }

        $separator = $comma !== false ? ',' : ($dot !== false ? '.' : null);
        if ($separator === null) {
            return $value;
        }

        if (substr_count($value, $separator) > 1) {
            return str_replace($separator, '', $value);
        }

        if (preg_match('/^-?\d{1,3}'.preg_quote($separator, '/').'\d{3}$/', $value)) {
            return null;
        }

        return str_replace($separator, '.', $value);
    }

    /**
     * An amount read with the decimal mark the admin chose (016, FR-017):
     * the other mark may only group thousands in threes. Anything else is
     * null, never read some other way (FR-018).
     */
    private static function markedAmount(string $value, ImportDecimalMark $decimalMark): ?string
    {
        [$decimal, $thousands] = $decimalMark === ImportDecimalMark::DOT ? ['.', ','] : [',', '.'];
        $d = preg_quote($decimal, '/');
        $t = preg_quote($thousands, '/');

        if (! preg_match("/^-?(\d{1,3}({$t}\d{3})+|\d+)({$d}\d+)?$/", $value)) {
            return null;
        }

        return str_replace([$thousands, $decimal], ['', '.'], $value);
    }

    /** @param array<string, mixed> $values */
    private function currency(NormalizedRow $row, array $values): ?string
    {
        $raw = $this->text($values, 'currency');
        if ($raw === null) {
            return null;
        }

        $currency = strtoupper($raw);
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $row->error('currency', "currency must be a three-letter code such as USD or EGP; the file has \"{$raw}\".");

            return null;
        }

        return $currency;
    }

    /**
     * The guest named by the row's guest_* columns, then any in its `guests`
     * JSON column (the format the import accepted before v2).
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function guests(NormalizedRow $row, array $values, Hotel $hotel): array
    {
        $guests = [];

        $columns = ['guest_first_name', 'guest_last_name', 'guest_full_name', 'guest_phone', 'guest_email', 'guest_external_id'];
        if (collect($columns)->contains(fn (string $column) => $this->text($values, $column) !== null)) {
            [$firstName, $lastName] = $this->name($values);
            $guests[] = $this->guest($row, [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $this->text($values, 'guest_phone'),
                'email' => $this->text($values, 'guest_email'),
                'external_id' => $this->text($values, 'guest_external_id'),
                'is_primary' => $this->flag($row, $values['is_primary'] ?? null),
            ], $hotel, 'guest');
        }

        $raw = $this->text($values, 'guests');
        if ($raw === null) {
            return $guests;
        }

        try {
            $list = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $list = null;
        }

        if (! is_array($list) || ! array_is_list($list) || collect($list)->contains(fn ($item) => ! is_array($item))) {
            $row->error('guests', 'guests must be a JSON list of guest objects.');

            return $guests;
        }

        foreach ($list as $index => $item) {
            $guests[] = $this->guest($row, [
                'first_name' => $this->scalar($item['first_name'] ?? null),
                'last_name' => $this->scalar($item['last_name'] ?? null),
                'phone' => $this->scalar($item['phone_number'] ?? $item['phone'] ?? null),
                'email' => $this->scalar($item['email'] ?? null),
                'external_id' => $this->scalar($item['external_id'] ?? null),
                'guest_id' => $this->scalar($item['guest_id'] ?? null),
                'is_primary' => $this->flag($row, $item['is_primary'] ?? null),
            ], $hotel, "guests.{$index}");
        }

        return $guests;
    }

    /**
     * The row's guest name. Separate first and last name columns win; a
     * full-name column (016, FR-016) is split otherwise: "Smith, John" puts
     * the family name first, and "Mohamed Abdel Rahman" keeps a family name
     * of several words together after the first word.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: ?string, 1: ?string}
     */
    private function name(array $values): array
    {
        $first = $this->text($values, 'guest_first_name');
        $last = $this->text($values, 'guest_last_name');
        $full = $this->text($values, 'guest_full_name');

        if ($first !== null || $last !== null || $full === null) {
            return [$first, $last];
        }

        $full = preg_replace('/\s+/u', ' ', $full);
        if (str_contains($full, ',')) {
            [$last, $first] = array_map('trim', explode(',', $full, 2));
        } else {
            [$first, $last] = array_pad(explode(' ', $full, 2), 2, null);
        }

        return [$first === '' ? null : $first, $last === '' ? null : $last];
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return array<string, mixed>
     */
    private function guest(NormalizedRow $row, array $guest, Hotel $hotel, string $field): array
    {
        $phone = PhoneNormalizer::normalize($guest['phone'], $hotel->country_code);
        $guest['phone'] = $phone['digits'];
        if ($phone['warning'] === PhoneNormalizer::WARNING_COUNTRY_UNKNOWN) {
            $row->warn($phone['warning'], "The phone number in {$field} has no country code and the hotel has none set; it was kept as written.");
        } elseif ($phone['warning'] === PhoneNormalizer::WARNING_UNRECOGNIZED) {
            $row->warn($phone['warning'], "The phone number in {$field} is not a valid number for the hotel's country; it was kept as written. Check it for a typo.");
        }

        if ($guest['email'] !== null) {
            $guest['email'] = mb_strtolower($guest['email']);
            if (! filter_var($guest['email'], FILTER_VALIDATE_EMAIL)) {
                $row->error($field === 'guest' ? 'guest_email' : "{$field}.email", "\"{$guest['email']}\" is not a valid email address.");
            }
        }

        return [
            'first_name' => $guest['first_name'],
            'last_name' => $guest['last_name'],
            'phone' => $guest['phone'],
            'email' => $guest['email'],
            'external_id' => $guest['external_id'],
            'guest_id' => $guest['guest_id'] ?? null,
            'is_primary' => $guest['is_primary'],
            'field' => $field,
        ];
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim(Digits::ascii((string) $value));

        return $value === '' ? null : $value;
    }

    private function flag(NormalizedRow $row, mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $value = mb_strtolower(trim((string) $raw));

        return match (true) {
            in_array($value, ['1', 'true', 'yes', 'y', 'primary'], true) => true,
            in_array($value, ['0', 'false', 'no', 'n'], true) => false,
            default => tap(null, fn () => $row->error('is_primary', "is_primary must be yes or no; the file has \"{$raw}\".")),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{name: string, date: ?CarbonImmutable, time: ?string, pax: ?int}|null
     */
    private function activity(NormalizedRow $row, array $values, ImportDateOrder $order): ?array
    {
        $name = $this->text($values, 'activity_name');
        if ($name === null) {
            return null;
        }

        $date = $this->date($row, $values, 'activity_date', $order);
        if (! $date && ! isset($row->errors['activity_date'])) {
            $row->error('activity_date', "The booking for \"{$name}\" needs an activity_date.");
        }

        return [
            'name' => $name,
            'date' => $date,
            'time' => $this->time($row, $values),
            'pax' => $this->wholeNumber($row, $values, 'activity_pax', min: 1),
        ];
    }

    /** @param array<string, mixed> $values */
    private function time(NormalizedRow $row, array $values): ?string
    {
        $raw = $values['activity_time'] ?? null;
        if (blank($raw)) {
            return null;
        }

        // A spreadsheet time cell is a fraction of a day.
        if (is_float($raw) || (is_numeric($raw) && (float) $raw < 1)) {
            $minutes = (int) round((float) $raw * 24 * 60);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?\s*([ap])?\.?m?\.?$/i', trim((string) $raw), $m)) {
            $hour = (int) $m[1];
            $minute = (int) ($m[2] ?? 0);
            if (isset($m[3]) && $m[3] !== '') {
                $hour = $hour % 12 + (strtolower($m[3]) === 'p' ? 12 : 0);
            }

            if ($hour < 24 && $minute < 60) {
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }

        $row->error('activity_time', "activity_time must be a time such as 18:30; the file has \"{$raw}\".");

        return null;
    }
}
