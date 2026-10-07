<?php

namespace App\Http\Resources;

use App\Models\KnowledgeIndexRebuild;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KnowledgeIndexRebuild
 */
class KnowledgeIndexRebuildResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope?->value,
            'hotel_id' => $this->hotel_id,
            'status' => $this->status?->value,
            'total' => $this->total,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'failures' => $this->failures ?? [],
            'requested_by' => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
