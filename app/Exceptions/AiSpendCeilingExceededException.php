<?php

namespace App\Exceptions;

use App\Models\HotelGroup;
use RuntimeException;

/**
 * Thrown when an account has spent past its hard daily AI ceiling.
 *
 * Distinct from every other failure on purpose: a caller that wants to answer
 * a guest differently when the account is stopped can catch this without
 * catching genuine provider errors too.
 */
class AiSpendCeilingExceededException extends RuntimeException
{
    public function __construct(
        public readonly HotelGroup $account,
        public readonly float $spentUsd,
        public readonly float $ceilingUsd,
    ) {
        parent::__construct(sprintf(
            'Account [%s] has spent $%s on AI today, at or past its daily ceiling of $%s. Further AI calls are stopped until tomorrow.',
            $account->name,
            number_format($spentUsd, 4),
            number_format($ceilingUsd, 2),
        ));
    }
}
