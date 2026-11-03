<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesExportFormat;

/**
 * Export of the booking list: its filters (activity, reservation, dates and
 * open cancellation requests included), search and sort, plus the format.
 */
class BookingExportRequest extends BookingIndexRequest
{
    use ValidatesExportFormat;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->exportRules()];
    }
}
