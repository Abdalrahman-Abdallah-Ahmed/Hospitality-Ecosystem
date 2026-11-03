<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesExportFormat;

/**
 * Export of the reservation list: its filters (room type and room included),
 * search and sort, plus the format.
 */
class ReservationExportRequest extends ReservationIndexRequest
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
