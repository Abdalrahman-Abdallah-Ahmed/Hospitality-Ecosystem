<?php

namespace App\Http\Resources;

use App\Support\Knowledge\KnowledgeSources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInsightsResource extends JsonResource
{
    /**
     * Which cited sources are live, when the caller already looked them up
     * for a whole page; otherwise this insight's are looked up on their own.
     *
     * @var array<string, bool>|null
     */
    private ?array $citationAvailability = null;

    /**
     * @param  array<string, bool>  $availability  from KnowledgeSources::availability()
     */
    public function withCitationAvailability(array $availability): static
    {
        $this->citationAvailability = $availability;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $citations = $this->citations ?? [];
        $live = $this->citationAvailability ?? KnowledgeSources::availability($citations);

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
            // The knowledge sources it relied on, as they were when it was
            // generated; `available` is false once a source can no longer
            // be opened (SPEC-090).
            'citations' => array_map(fn (array $citation) => [
                'source_type' => $citation['source_type'] ?? null,
                'source_id' => $citation['source_id'] ?? null,
                'title' => $citation['title'] ?? null,
                'location' => $citation['location'] ?? null,
                'scope' => $citation['scope'] ?? null,
                'last_updated' => $citation['last_updated'] ?? null,
                'available' => $live[KnowledgeSources::key((string) ($citation['source_type'] ?? ''), (string) ($citation['source_id'] ?? ''))] ?? false,
            ], $citations),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
