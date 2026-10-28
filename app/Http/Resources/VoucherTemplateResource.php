<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A voucher template. Storage paths and the disk are never exposed; the
 * image is fetched through the authenticated image endpoint.
 */
class VoucherTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'name' => $this->name,
            'language' => $this->language?->value,
            'is_default' => $this->is_default,
            'image' => [
                'mime_type' => $this->mime_type,
                'width_px' => $this->width_px,
                'height_px' => $this->height_px,
                'size_bytes' => $this->size_bytes,
                'url' => "/api/voucher-templates/{$this->id}/image",
            ],
            'layout' => $this->layout ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
