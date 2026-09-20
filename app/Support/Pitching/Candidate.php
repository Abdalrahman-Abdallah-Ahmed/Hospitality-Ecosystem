<?php

namespace App\Support\Pitching;

/**
 * A pending recommendation this turn may offer, carrying the reason
 * RecommendationAgent gave for it.
 */
final readonly class Candidate
{
    public function __construct(
        public string $recommendationId,
        public string $activityId,
        public string $name,
        public ?string $reason,
        public int $priority,
        public ?string $predictedConfidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $rank): array
    {
        return [
            'rank' => $rank,
            'recommendation_id' => $this->recommendationId,
            'activity_id' => $this->activityId,
            'name' => $this->name,
            'reason' => $this->reason,
            'priority' => $this->priority,
            'predicted_confidence' => $this->predictedConfidence,
        ];
    }
}
