<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInsightsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'insightable_id' => $this->insightable_id,
            'insightable_type' => $this->insightable_type,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'insight_type' => $this->insight_type,
            'evidence_level' => $this->evidence_level,
            'evidence_sources' => $this->evidence_sources,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
