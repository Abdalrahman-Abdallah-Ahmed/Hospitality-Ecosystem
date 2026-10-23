<?php

namespace App\Support\Import\Reservations;

use App\Models\ReservationImportMapping;

/**
 * Which field each column of a previewed file probably is (016, FR-006 to
 * FR-008). The hotel's last confirmed mapping comes first, including a
 * column it chose to leave unmapped; then the header synonym table; then a
 * header already named like a field. A field goes to the first column that
 * claims it, so no two columns are suggested the same one.
 */
class ImportColumnSuggester
{
    /**
     * @param  array<int, string>  $headers  sheet position => header text
     * @return array<int, ?string> sheet position => suggested field
     */
    public function suggest(array $headers, ?ReservationImportMapping $saved): array
    {
        $synonyms = config('reservation_import.header_synonyms', []);
        $canonical = config('reservation_import.canonical_columns', []);
        $remembered = $saved?->columns ?? [];

        $suggestions = [];
        $taken = [];
        foreach ($headers as $position => $header) {
            $key = ImportFileReader::normalizeHeader($header);

            $field = match (true) {
                $key === '' => null,
                array_key_exists($key, $remembered) => $remembered[$key],
                default => $synonyms[$key] ?? (in_array($key, $canonical, true) ? $key : null),
            };

            if ($field !== null && (isset($taken[$field]) || ! in_array($field, $canonical, true))) {
                $field = null;
            }

            if ($field !== null) {
                $taken[$field] = true;
            }
            $suggestions[$position] = $field;
        }

        return $suggestions;
    }

    /**
     * The header row the hotel used last, while the file has that many rows
     * within the choosable range; otherwise the first row.
     */
    public function suggestedHeaderRow(?ReservationImportMapping $saved, int $lastRow): int
    {
        $row = $saved?->header_row ?? 1;
        $limit = min((int) config('reservation_import.preview_header_rows', 10), $lastRow);

        return $row >= 1 && $row <= $limit ? $row : 1;
    }
}
