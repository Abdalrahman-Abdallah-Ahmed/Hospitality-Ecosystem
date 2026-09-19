<?php

namespace App\Support\Pitching;

use App\Models\PitchDecision;

/**
 * One guest turn's pitching state, shared by the job and the concierge's
 * tools for the length of the turn.
 *
 * Mutable on purpose, in one way only: a tool that escalates or files a
 * service request marks the turn, and nothing may pitch for the rest of it.
 */
final class PitchTurn
{
    private bool $blockingRequestMade = false;

    /**
     * @param  list<Candidate>  $shortlist
     */
    public function __construct(
        public readonly ?PitchDecision $decision,
        public readonly bool $eligible,
        public readonly array $shortlist = [],
    ) {}

    /**
     * A turn that could not be evaluated. Fails closed: nothing is pitched.
     */
    public static function ineligible(): self
    {
        return new self(null, false);
    }

    /**
     * The guest escalated or reported a problem during this turn, so no pitch
     * may follow in the same reply.
     */
    public function markBlockingRequest(): void
    {
        $this->blockingRequestMade = true;
    }

    public function mayPitch(): bool
    {
        return $this->eligible && ! $this->blockingRequestMade;
    }
}
