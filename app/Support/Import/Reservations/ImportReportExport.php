<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportRowOutcome;
use App\Models\ReservationImport;
use App\Models\ReservationImportRow;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The downloadable report (FR-028): every row that was not imported, and
 * every warning, so staff can fix the file and upload it again. A row with
 * both appears once per line it needs.
 */
class ImportReportExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly ReservationImport $import) {}

    public function query(): Builder
    {
        return ReservationImportRow::query()
            ->where('reservation_import_id', $this->import->id)
            ->where(fn (Builder $query) => $query->where('outcome', '!=', ImportRowOutcome::IMPORTED->value)
                ->orWhereRaw("warnings <> '[]'::jsonb"))
            ->orderBy('row_number');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['row_number', 'external_reference', 'outcome', 'fields', 'reason', 'warning'];
    }

    /**
     * @param  ReservationImportRow  $row
     * @return list<list<mixed>>
     */
    public function map($row): array
    {
        $lines = [];
        $base = [$row->row_number, $row->external_reference, $row->outcome?->value, implode(', ', $row->fields ?? [])];

        if ($row->outcome !== ImportRowOutcome::IMPORTED) {
            $lines[] = [...$base, $row->reason, null];
        }

        foreach ($row->warnings ?? [] as $warning) {
            $lines[] = [...$base, null, $warning['message']];
        }

        return $lines;
    }
}
