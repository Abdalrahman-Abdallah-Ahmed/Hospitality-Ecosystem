<?php

namespace App\Http\Resources;

use App\Enums\ImportDecimalMark;
use App\Models\ReservationImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReservationImport
 */
class ReservationImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'file_name' => $this->file_name,
            'uploaded_by' => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null,
            'dry_run' => $this->dry_run,
            'date_order' => $this->date_order?->value,
            // How the file was read (016): its preview, header row, the
            // confirmed column map (null when headers were matched by name)
            // and the decimal mark of its amounts.
            'preview_id' => $this->reservation_import_preview_id,
            'header_row' => $this->header_row ?? 1,
            'column_map' => $this->column_map === null ? null : (object) $this->column_map,
            'decimal_mark' => ($this->decimal_mark ?? ImportDecimalMark::AUTO)->value,
            'status' => $this->status?->value,
            'stage' => $this->stage?->value,
            'progress' => [
                'processed_rows' => $this->processed_rows,
                'total_rows' => $this->total_rows,
            ],
            'totals' => [
                'imported' => $this->imported,
                'skipped' => $this->skipped,
                'duplicate' => $this->duplicate,
                'invalid' => $this->invalid,
                'unresolved' => $this->unresolved,
            ],
            'created_rooms' => $this->created_rooms ?? [],
            'created_room_types' => $this->created_room_types ?? [],
            'unrecognized_columns' => $this->unrecognized_columns ?? [],
            // Rows carrying at least one warning; counted by the query when it can be.
            'warnings_count' => (int) ($this->warned_rows_count ?? $this->rows()->whereRaw("warnings <> '[]'::jsonb")->count()),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
