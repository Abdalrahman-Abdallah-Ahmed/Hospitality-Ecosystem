<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherEmailRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status?->value,
            'status_reason' => $this->status_reason,
            'recipient' => $this->recipient,
            'language' => $this->language?->value,
            'voucher_template_id' => $this->voucher_template_id,
            'show_total' => $this->show_total,
            'unplaced' => $this->unplaced ?? [],
            'created_at' => $this->created_at,
            'sent_at' => $this->sent_at,
        ];
    }
}
