<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Handed to a blocked job's failed() handler so it can clean up. It is never
 * thrown: a blocked job is a deliberate stop, not a failure.
 */
class HotelSuspended extends RuntimeException
{
    public function __construct(public readonly string $hotelId)
    {
        parent::__construct('The hotel is suspended.');
    }
}
