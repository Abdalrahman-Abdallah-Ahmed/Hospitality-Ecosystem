<?php

namespace App\Support\Pitching;

/**
 * An active activity that survived every per-activity exclusion for a turn.
 */
final readonly class Candidate
{
    /**
     * @param  list<string>  $openDates  possible start dates, Y-m-d in the hotel's timezone
     */
    public function __construct(
        public string $activityId,
        public string $name,
        public array $openDates,
        public bool $capacityKnown,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $rank): array
    {
        return [
            'rank' => $rank,
            'activity_id' => $this->activityId,
            'name' => $this->name,
            'open_dates' => $this->openDates,
            'capacity' => $this->capacityKnown ? 'known' : 'unknown',
        ];
    }
}
