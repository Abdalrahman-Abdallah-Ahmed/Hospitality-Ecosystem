<?php

namespace App\Support\Reservations;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns the database's "one room, two bookings" rejection into the usual
 * validation error (spec 020 FR-010, research R6).
 *
 * The rule checks in RoomAssignmentRules give the precise message first; the
 * `reservation_rooms_no_overlap` constraint only fires when two requests race
 * or a path skipped the check. PostgreSQL aborts the transaction on that
 * error, so wrap the outermost transaction, never something inside it.
 */
class RoomHoldConflict
{
    public const CONSTRAINT = 'reservation_rooms_no_overlap';

    public const MESSAGE = 'A room on this booking was just taken by another reservation for overlapping nights. Reload and choose another room.';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    public static function rethrow(callable $callback, string $field = 'rooms'): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if (self::matches($e)) {
                throw ValidationException::withMessages([$field => self::MESSAGE]);
            }

            throw $e;
        }
    }

    public static function matches(Throwable $e): bool
    {
        return $e instanceof QueryException
            && $e->getCode() === '23P01'
            && str_contains($e->getMessage(), self::CONSTRAINT);
    }
}
