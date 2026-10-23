<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesImportHeaderRow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which row of a previewed file holds the headers (016, FR-002a).
 */
class ShowReservationImportPreviewRequest extends FormRequest
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
            'header_row' => ['nullable', ...$this->headerRowRules()],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [$this->headerRowWithinFile(...)];
    }
}
