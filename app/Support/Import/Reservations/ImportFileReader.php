<?php

namespace App\Support\Import\Reservations;

use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Parse stage: reads the first sheet of an uploaded file and puts every row
 * under the import's canonical column names. Headers are normalized (case,
 * spacing, punctuation) and then translated through the synonym table in
 * config/reservation_import.php; anything not canonical is reported once as
 * unrecognized rather than per row.
 */
class ImportFileReader
{
    /**
     * @return array{rows: list<array{row_number: int, values: array<string, mixed>}>, unrecognized: list<string>}
     *
     * @throws ImportFailed
     */
    public function read(string $path, ?string $disk = null): array
    {
        try {
            $sheets = Excel::toArray(new class {}, $path, $disk);
        } catch (Throwable $e) {
            report($e);

            throw new ImportFailed('The file could not be read. Upload an .xlsx, .xls or .csv file with a header row.');
        }

        $sheet = $sheets[0] ?? [];
        $headerRow = array_shift($sheet) ?? [];

        [$columns, $unrecognized] = $this->columns($headerRow);
        $this->assertRequiredColumns($columns);

        $rows = [];
        foreach ($sheet as $index => $cells) {
            $values = [];
            foreach ($columns as $position => $column) {
                // A canonical column named twice keeps its first non-empty value.
                if ($column !== null && ! filled($values[$column] ?? null)) {
                    $values[$column] = $cells[$position] ?? null;
                }
            }

            // +2: 1-based, plus the header row.
            $rows[] = ['row_number' => $index + 2, 'values' => $values];
        }

        return ['rows' => $this->withoutTrailingBlankRows($rows), 'unrecognized' => $unrecognized];
    }

    /**
     * "Guest Phone", "guest-phone" and "GUEST_PHONE" are one header.
     */
    private static function normalizeHeader(mixed $header): string
    {
        $header = mb_strtolower(trim((string) $header));
        $header = preg_replace('/[^\p{L}\p{N}]+/u', '_', $header);

        return trim($header, '_');
    }

    /**
     * @param  list<mixed>  $headerRow
     * @return array{0: array<int, ?string>, 1: list<string>}
     */
    private function columns(array $headerRow): array
    {
        $synonyms = config('reservation_import.header_synonyms', []);
        $canonical = config('reservation_import.canonical_columns', []);
        $columns = [];
        $unrecognized = [];

        foreach ($headerRow as $position => $raw) {
            $header = self::normalizeHeader($raw);
            if ($header === '') {
                $columns[$position] = null;

                continue;
            }

            $column = $synonyms[$header] ?? $header;
            if (in_array($column, $canonical, true)) {
                $columns[$position] = $column;
            } else {
                $columns[$position] = null;
                $unrecognized[] = trim((string) $raw);
            }
        }

        return [$columns, array_values(array_unique($unrecognized))];
    }

    /**
     * @param  array<int, ?string>  $columns
     *
     * @throws ImportFailed
     */
    private function assertRequiredColumns(array $columns): void
    {
        $present = array_filter($columns);
        $missing = array_values(array_diff(config('reservation_import.required_columns', []), $present));

        $identifiers = config('reservation_import.guest_identifier_columns', []);
        if (array_intersect($identifiers, $present) === []) {
            $missing[] = implode(' or ', $identifiers);
        }

        if ($missing !== []) {
            throw new ImportFailed('The file is missing required columns: '.implode(', ', $missing).'. Add them to the header row and upload it again.');
        }
    }

    /**
     * Spreadsheets often carry blank rows after the data. They are not data
     * rows, so they are not counted (FR-025); a blank row between data rows
     * stays and is reported as skipped.
     *
     * @param  list<array{row_number: int, values: array<string, mixed>}>  $rows
     * @return list<array{row_number: int, values: array<string, mixed>}>
     */
    private function withoutTrailingBlankRows(array $rows): array
    {
        while ($rows !== [] && self::isBlank(end($rows)['values'])) {
            array_pop($rows);
        }

        return $rows;
    }

    /** @param array<string, mixed> $values */
    public static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (filled(is_string($value) ? trim($value) : $value)) {
                return false;
            }
        }

        return true;
    }
}
