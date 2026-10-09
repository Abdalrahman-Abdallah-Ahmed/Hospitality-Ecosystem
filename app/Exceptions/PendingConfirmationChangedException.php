<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The admin confirmed a list of actions that is no longer the one waiting:
 * confirming what they did not see would be worse than asking again. Carries
 * the actions that are waiting now, so the client can show them.
 */
class PendingConfirmationChangedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $pendingConfirmation
     */
    public function __construct(public readonly ?array $pendingConfirmation)
    {
        parent::__construct('The actions waiting for confirmation have changed. Review them again.');
    }
}
