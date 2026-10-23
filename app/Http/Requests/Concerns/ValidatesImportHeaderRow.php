<?php

namespace App\Http\Requests\Concerns;

use App\Models\ReservationImportPreview;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The header row of a column-mapping preview (016): one of the first rows
 * the screen offers, and a row the file actually has.
 */
trait ValidatesImportHeaderRow
{
    /**
     * An expired upload is gone as far as the admin is concerned: say so
     * before judging the request, whose body no longer matters (FR-005).
     * Only to someone who may see the upload, so the answer never reveals
     * another hotel's.
     */
    protected function abortIfPreviewExpired(): void
    {
        $preview = $this->route('reservationImportPreview');
        if (! $preview instanceof ReservationImportPreview || ! $preview->isExpired()) {
            return;
        }

        Gate::forUser($this->user())->authorize('view', $preview);
        abort(410, 'This upload has expired. Upload the file again.');
    }

    /**
     * @return list<string>
     */
    protected function headerRowRules(): array
    {
        return ['integer', 'min:1', 'max:'.(int) config('reservation_import.preview_header_rows', 10)];
    }

    protected function headerRowWithinFile(Validator $validator): void
    {
        $preview = $this->route('reservationImportPreview');
        $row = $this->input('header_row');

        if ($preview instanceof ReservationImportPreview && is_numeric($row) && ! $validator->errors()->has('header_row') && (int) $row > $preview->last_row) {
            $validator->errors()->add('header_row', "The file has only {$preview->last_row} rows.");
        }
    }
}
