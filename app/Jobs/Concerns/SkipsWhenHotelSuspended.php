<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SkipIfHotelSuspended;

/**
 * For a job with no other middleware: a suspended hotel's job doesn't run
 * (spec 024). The job says which hotel through HasHotel::hotelId().
 */
trait SkipsWhenHotelSuspended
{
    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SkipIfHotelSuspended];
    }
}
