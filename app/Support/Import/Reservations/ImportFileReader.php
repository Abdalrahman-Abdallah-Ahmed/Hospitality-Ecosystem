<?php

namespace App\Support\Import\Reservations;

use Maatwebsite\Excel\Concerns\WithFormatData;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Parse stage: reads the first sheet of an uploaded file and puts every row
 * under the import's canonical column names.
 *
 * A direct upload has no column map: its header row (row 1) is normalized
 * (case, spacing, punctuation) and translated through the synonym table in
 * config/reservation_import.php. An import confirmed from a preview (016)
 * brings the header row and column map the admin chose, and only the mapped
 * columns are read. Either way, anything not read is reported once as
 * unrecognized rather than per row.
 */
class ImportFileReader
{
    private const UNREADABLE = 'The file could not be read. Upload an .xlsx, .xls or .csv file with a header row.';

    /**
     * @param  array<int|string, ?string>|null  $columnMap  sheet position => canonical field, or null to leave it unread
     * @return array{rows: list<array{row_number: int, values: array<string, mixed>}>, unrecognized: list<string>}
     *
     * @throws ImportFailed
     */
    public function read(string $path, ?string $disk = null, int $headerRow = 1, ?array $columnMap = null): array
    {
        $sheet = $this->firstSheet(fn () => Excel::toArray(new class {}, $path, $disk));

        // Rows above the header (report titles) are not data.
        $sheet = array_slice($sheet, $headerRow - 1);
        $header = array_shift($sheet) ?? [];

        [$columns, $unrecognized] = $columnMap === null ? $this->columns($header) : $this->mappedColumns($header, $columnMap);
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

            // The file's own row number: 1-based, after the header row.
            $rows[] = ['row_number' => $index + $headerRow + 1, 'values' => $values];
        }

        return ['rows' => $this->withoutTrailingBlankRows($rows), 'unrecognized' => $unrecognized];
    }

    /**
     * The head of the first sheet for a column-mapping preview (016), read
     * once with cells formatted as shown in the spreadsheet, so a date cell
     * is a date and not a serial number.
     *
     * @return array{rows: list<list<string>>, last_row: int}
     *
     * @throws ImportFailed
     */
    public function head(string $path, ?string $disk = null): array
    {
        // Cell formats are only loaded outside read-only mode; the import
        // itself keeps reading data only.
        $readOnly = config('excel.imports.read_only');
        config(['excel.imports.read_only' => false]);
        try {
            $sheet = $this->firstSheet(fn () => Excel::toArray(new class implements WithFormatData {}, $path, $disk));
        } finally {
            config(['excel.imports.read_only' => $readOnly]);
        }

        $lastRow = 0;
        foreach ($sheet as $index => $cells) {
            if (! self::isBlank($cells)) {
                $lastRow = $index + 1;
            }
        }

        $rows = array_map(function (array $cells): array {
            $cells = array_map(fn (mixed $cell) => trim((string) $cell), array_values($cells));
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            return $cells;
        }, array_slice($sheet, 0, (int) config('reservation_import.preview_head_rows', 30)));

        return ['rows' => $rows, 'last_row' => $lastRow];
    }

    /**
     * @param  callable(): array<int, array<int, array<int, mixed>>>  $read
     * @return array<int, array<int, mixed>>
     *
     * @throws ImportFailed
     */
    private function firstSheet(callable $read): array
    {
        try {
            return $read()[0] ?? [];
        } catch (Throwable $e) {
            report($e);

            throw new ImportFailed(self::UNREADABLE);
        }
    }

    /**
     * "Guest Phone", "guest-phone" and "GUEST_PHONE" are one header.
     */
    public static function normalizeHeader(mixed $header): string
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
     * The admin's confirmed mapping is the only source of column meaning:
     * the synonym table is not consulted, and every unmapped column with a
     * header is reported as unrecognized.
     *
     * @param  list<mixed>  $headerRow
     * @param  array<int|string, ?string>  $columnMap
     * @return array{0: array<int, ?string>, 1: list<string>}
     */
    private function mappedColumns(array $headerRow, array $columnMap): array
    {
        $columns = [];
        foreach ($columnMap as $position => $field) {
            $columns[(int) $position] = $field;
        }

        $unrecognized = [];
        foreach ($headerRow as $position => $raw) {
            if (($columns[$position] ?? null) === null && trim((string) $raw) !== '') {
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
