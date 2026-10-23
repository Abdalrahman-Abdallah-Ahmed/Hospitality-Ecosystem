<?php

namespace App\Support\Import\Reservations;

use App\Models\ReservationImportMapping;
use App\Models\ReservationImportPreview;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Builds the column-mapping screen of a preview for one header row (016),
 * from the sheet head stored at upload: the file is never read again.
 */
class ImportPreviewBuilder
{
    private const SAMPLES = 5;

    public function __construct(private ImportColumnSuggester $suggester) {}

    /**
     * @return array{header_row: int, rows: list<list<string>>, columns: list<array{position: int, letter: string, header: string, samples: list<string>, suggested_field: ?string}>, data_rows: int, fields: list<array{key: string, label: string, required: bool, guest_identifier: bool}>}
     */
    public function build(ReservationImportPreview $preview, int $headerRow, ?ReservationImportMapping $saved): array
    {
        $head = $preview->head_rows ?? [];
        $header = $head[$headerRow - 1] ?? [];
        $below = array_slice($head, $headerRow);

        // A data column can be wider than its header (blank header cells).
        $width = max(count($header), ...array_map('count', [[], ...$below]));

        $headers = [];
        for ($position = 0; $position < $width; $position++) {
            $headers[$position] = (string) ($header[$position] ?? '');
        }
        $suggestions = $this->suggester->suggest($headers, $saved);

        $columns = [];
        foreach ($headers as $position => $text) {
            $letter = Coordinate::stringFromColumnIndex($position + 1);
            $samples = array_values(array_filter(array_map(fn (array $row) => (string) ($row[$position] ?? ''), $below), fn (string $value) => $value !== ''));

            $columns[] = [
                'position' => $position,
                'letter' => $letter,
                'header' => $text !== '' ? $text : "Column {$letter}",
                'samples' => array_slice($samples, 0, self::SAMPLES),
                'suggested_field' => $suggestions[$position],
            ];
        }

        return [
            'header_row' => $headerRow,
            'rows' => array_slice($head, 0, (int) config('reservation_import.preview_header_rows', 10)),
            'columns' => $columns,
            'data_rows' => max(0, $preview->last_row - $headerRow),
            'fields' => self::fields(),
        ];
    }

    /**
     * Every field a column can be mapped to, in config order.
     *
     * @return list<array{key: string, label: string, required: bool, guest_identifier: bool}>
     */
    public static function fields(): array
    {
        $labels = config('reservation_import.field_labels', []);
        $required = config('reservation_import.required_columns', []);
        $identifiers = config('reservation_import.guest_identifier_columns', []);

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'required' => in_array($key, $required, true),
            'guest_identifier' => in_array($key, $identifiers, true),
        ], config('reservation_import.canonical_columns', []));
    }

    /** A field's label, for messages. */
    public static function label(string $key): string
    {
        return config("reservation_import.field_labels.{$key}", $key);
    }
}
