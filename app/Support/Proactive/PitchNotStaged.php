<?php

namespace App\Support\Proactive;

use App\Enums\ProactiveSkipReason;
use RuntimeException;

/**
 * A proactive pitch could not be staged after all — a guest turn or a change
 * to the recommendation got there first. Thrown inside the staging
 * transaction so the half-written decision rolls back.
 */
final class PitchNotStaged extends RuntimeException
{
    public function __construct(public readonly ProactiveSkipReason $reason)
    {
        parent::__construct("Proactive pitch not staged: {$reason->value}.");
    }
}
