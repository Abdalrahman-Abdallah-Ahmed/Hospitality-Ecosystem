<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeChunkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The embedding is deliberately absent: 1536 floats per chunk are of no
     * use to a client and would dwarf every other field in the payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'chunkable_type' => $this->chunkable_type,
            'chunkable_id' => $this->chunkable_id,
            'category' => $this->category,
            'chunk_index' => $this->chunk_index,
            'content' => $this->content,
            'token_count' => $this->token_count,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
