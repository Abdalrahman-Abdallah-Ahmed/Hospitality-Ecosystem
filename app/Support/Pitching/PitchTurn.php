<?php

namespace App\Support\Pitching;

use App\Models\PitchDecision;

/**
 * One guest turn's pitching state, shared by the job and the concierge's
 * tools for the length of the turn.
 *
 * Mutable on purpose, in two ways only: a tool that escalates or files a
 * service request marks the turn, and nothing may pitch for the rest of it;
 * and the pitch tool stages the one recommendation it offered, after which
 * nothing more may be staged.
 */
final class PitchTurn
{
    private bool $blockingRequestMade = false;

    private ?Candidate $staged = null;

    private ?int $stagedRank = null;

    /**
     * @param  list<Candidate>  $shortlist
     */
    public function __construct(
        public readonly ?PitchDecision $decision,
        public readonly bool $eligible,
        public readonly array $shortlist = [],
        // The guest's message this turn, which a pitch must quote.
        public readonly string $messageText = '',
        // A pitch staged this turn would be the one retry after a decline.
        public readonly bool $isRetryTurn = false,
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
        return $this->eligible && ! $this->blockingRequestMade && $this->staged === null;
    }

    /**
     * The only candidate this turn may offer: the top of the shortlist, in
     * RecommendationAgent's own order (WP-17.1).
     */
    public function offer(): ?Candidate
    {
        return $this->shortlist[0] ?? null;
    }

    public function stage(Candidate $candidate, int $rank): void
    {
        $this->staged = $candidate;
        $this->stagedRank = $rank;
    }

    public function staged(): ?Candidate
    {
        return $this->staged;
    }

    public function stagedRank(): ?int
    {
        return $this->stagedRank;
    }
}
