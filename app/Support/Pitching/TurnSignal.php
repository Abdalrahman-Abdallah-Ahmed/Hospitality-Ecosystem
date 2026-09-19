<?php

namespace App\Support\Pitching;

use App\Enums\PitchOpening;

/**
 * What the turn classifier read in the guest's message, after code has
 * validated it.
 */
final readonly class TurnSignal
{
    public function __construct(
        public bool $complaint,
        public ?PitchOpening $opening,
        public ?string $interestCategoryId,
        public string $evidenceQuote,
    ) {}
}
