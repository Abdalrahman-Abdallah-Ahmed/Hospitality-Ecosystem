<?php

namespace App\Http\Resources;

use App\Models\ReservationImportPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A column-mapping preview (016). The mapping screen (rows, columns, fields)
 * is only included when the controller built it for a header row, so the
 * list of open uploads stays light.
 *
 * @mixin ReservationImportPreview
 */
class ReservationImportPreviewResource extends JsonResource
{
    /** @var array<string, mixed>|null ImportPreviewBuilder::build() output */
    private ?array $screen = null;

    private ?int $suggestedHeaderRow = null;

    /**
     * @param  array<string, mixed>  $screen
     */
    public function withScreen(array $screen, int $suggestedHeaderRow): static
    {
        $this->screen = $screen;
        $this->suggestedHeaderRow = $suggestedHeaderRow;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $screen = $this->screen;

        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'file_name' => $this->file_name,
            'uploaded_by' => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'suggested_header_row' => $this->when($this->suggestedHeaderRow !== null, $this->suggestedHeaderRow),
            'header_row' => $this->when($screen !== null, fn () => $screen['header_row']),
            'data_rows' => $this->when($screen !== null, fn () => $screen['data_rows']),
            'rows' => $this->when($screen !== null, fn () => $screen['rows']),
            'columns' => $this->when($screen !== null, fn () => $screen['columns']),
            'fields' => $this->when($screen !== null, fn () => $screen['fields']),
            // Newest first: import ids are time-ordered UUIDv7s.
            'imports' => $this->imports->sortByDesc('id')->values()
                ->map(fn ($import) => [
                    'id' => $import->id,
                    'dry_run' => $import->dry_run,
                    'status' => $import->status?->value,
                    'created_at' => $import->created_at,
                ])->all(),
        ];
    }
}
