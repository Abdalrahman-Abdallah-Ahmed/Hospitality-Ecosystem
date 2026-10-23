<?php

namespace App\Http\Resources;

use App\Models\ReservationImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReservationImportRow
 */
class ReservationImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'external_reference' => $this->external_reference,
            'outcome' => $this->outcome?->value,
            'stage' => $this->stage?->value,
            'fields' => $this->fields ?? [],
            'reason' => $this->reason,
            'warnings' => $this->warnings ?? [],
            'reservation_id' => $this->reservation_id,
        ];
    }
}
