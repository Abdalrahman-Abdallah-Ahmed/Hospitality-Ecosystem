<?php

namespace App\Support\Pitching;

use Carbon\CarbonInterface;

/**
 * Where a reservation stands under the decline-retry rule (D10, SPEC-074).
 *
 * After the guest declines an unsolicited pitch, one different approved
 * activity may follow, within 24 hours of that first pitch. Then the stay is
 * closed to unsolicited pitching. Derived from pitch decisions each time,
 * never stored.
 */
final readonly class PitchFlow
{
    public const RETRY_WINDOW_HOURS = 24;

    public function __construct(
        /** An unsolicited pitch this reservation was declined. */
        public bool $declined,
        /** When the declined flow's first pitch was made. */
        public ?CarbonInterface $firstPitchAt,
        /** The one retry after the decline has been made. */
        public bool $retryUsed,
        /** Unsolicited pitches so far, the retry not counted (FR-021). */
        public int $pitchesTowardCap,
        public CarbonInterface $now,
    ) {}

    public static function none(CarbonInterface $now, int $pitchesTowardCap = 0): self
    {
        return new self(false, null, false, $pitchesTowardCap, $now);
    }

    public function windowClosed(): bool
    {
        return $this->declined
            && $this->firstPitchAt !== null
            && $this->now->greaterThanOrEqualTo($this->firstPitchAt->copy()->addHours(self::RETRY_WINDOW_HOURS));
    }

    /**
     * No more unsolicited pitches this stay.
     */
    public function closed(): bool
    {
        return $this->declined && ($this->retryUsed || $this->windowClosed());
    }

    /**
     * The next unsolicited pitch would be the one retry.
     */
    public function isRetryNext(): bool
    {
        return $this->declined && ! $this->closed();
    }
}
