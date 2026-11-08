<?php

namespace App\Support\Superadmin;

use App\Exceptions\DomainRuleException;
use Illuminate\Database\QueryException;

/**
 * Turns the database's "two owners for one account" rejection into the usual
 * refusal (spec 025, research R4).
 *
 * The index is the only check, because a check before the write can't stop two
 * requests racing. That also means an ordinary second owner takes the same path
 * as the loser of a race. PostgreSQL aborts the transaction on that error, so
 * wrap the outermost transaction, never something inside it.
 */
class SecondOwner
{
    public const INDEX = 'users_one_owner_per_account';

    public const MESSAGE = 'This account already has an owner.';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws DomainRuleException
     */
    public static function rethrow(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if (self::matches($e)) {
                throw new DomainRuleException(self::MESSAGE, 422);
            }

            throw $e;
        }
    }

    /**
     * By name, so another unique column, such as an email taken in a race, keeps
     * its own failure.
     */
    public static function matches(QueryException $e): bool
    {
        return $e->getCode() === '23505' && str_contains($e->getMessage(), self::INDEX);
    }
}
