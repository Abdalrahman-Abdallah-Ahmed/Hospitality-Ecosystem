<?php

namespace App\Support\Pitching;

/**
 * The recommendations a turn may offer, and why the others were left out.
 * Serialised into pitch_decisions.candidates in the shape the engagement
 * report reads.
 */
final readonly class CandidateList
{
    /**
     * @param  list<Candidate>  $shortlist  in the recommendation agent's own order
     * @param  list<array{recommendation_id: string, activity_id: string, name: string, reason: string, detail: ?string}>  $excluded
     */
    public function __construct(
        public int $considered,
        public array $shortlist,
        public array $excluded,
    ) {}

    public function isEmpty(): bool
    {
        return $this->shortlist === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'considered' => $this->considered,
            'shortlist' => array_map(
                fn (Candidate $candidate, int $index) => $candidate->toArray($index + 1),
                $this->shortlist,
                array_keys($this->shortlist),
            ),
            'excluded' => $this->excluded,
        ];
    }
}
