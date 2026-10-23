<?php

namespace App\Http\Requests;

use App\Enums\ImportDateOrder;
use App\Enums\ImportDecimalMark;
use App\Http\Requests\Concerns\ValidatesImportHeaderRow;
use App\Models\ReservationImportPreview;
use App\Support\Import\Reservations\ImportPreviewBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Confirming a column mapping (016, FR-009 to FR-011). Every problem with
 * the mapping is reported at once, under `columns`, and nothing is created
 * until the whole request is valid.
 */
class ConfirmReservationImportPreviewRequest extends FormRequest
{
    use ValidatesImportHeaderRow;

    public function authorize(): bool
    {
        $this->abortIfPreviewExpired();

        return apiAuth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'header_row' => ['required', ...$this->headerRowRules()],
            // Sheet position => field, or null to leave the column unread.
            'columns' => ['present', 'array'],
            'columns.*' => ['nullable', 'string'],
            'date_order' => ['nullable', Rule::enum(ImportDateOrder::class)],
            'decimal_mark' => ['nullable', Rule::enum(ImportDecimalMark::class)],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [$this->headerRowWithinFile(...), $this->mappingRules(...)];
    }

    /**
     * The confirmed mapping with integer positions, unmapped ones included.
     *
     * @return array<int, ?string>
     */
    public function columnMap(): array
    {
        $map = [];
        foreach ($this->validated('columns') as $position => $field) {
            $map[(int) $position] = $field;
        }
        ksort($map);

        return $map;
    }

    private function mappingRules(Validator $validator): void
    {
        $preview = $this->route('reservationImportPreview');
        $columns = $this->input('columns');
        if (! $preview instanceof ReservationImportPreview || ! is_array($columns) || $validator->errors()->hasAny(['header_row', 'columns'])) {
            return;
        }

        $width = count(app(ImportPreviewBuilder::class)->build($preview, (int) $this->input('header_row'), null)['columns']);
        $known = array_column(ImportPreviewBuilder::fields(), 'key');
        $label = ImportPreviewBuilder::label(...);
        $problems = [];

        $mapped = [];
        foreach ($columns as $position => $field) {
            if (! ctype_digit((string) $position) || (int) $position >= $width) {
                $problems[] = "The file has no column at position {$position}.";
            }
            if ($field === null) {
                continue;
            }
            if (! in_array($field, $known, true)) {
                $problems[] = "\"{$field}\" is not a field this import reads.";

                continue;
            }
            $mapped[$field] = ($mapped[$field] ?? 0) + 1;
        }

        foreach ($mapped as $field => $count) {
            if ($count > 1) {
                $problems[] = "{$label($field)} is mapped to more than one column.";
            }
        }

        foreach (config('reservation_import.required_columns', []) as $field) {
            if (! isset($mapped[$field])) {
                $problems[] = "Map a column to {$label($field)}.";
            }
        }

        $identifiers = config('reservation_import.guest_identifier_columns', []);
        if (array_intersect($identifiers, array_keys($mapped)) === []) {
            $problems[] = 'Map a column to at least one of '.implode(', ', array_map($label, $identifiers)).'.';
        }

        if (isset($mapped['guest_full_name']) && (isset($mapped['guest_first_name']) || isset($mapped['guest_last_name']))) {
            $problems[] = "Map either {$label('guest_full_name')} or {$label('guest_first_name')} / {$label('guest_last_name')}, not both.";
        }

        foreach ($problems as $problem) {
            $validator->errors()->add('columns', $problem);
        }
    }
}
