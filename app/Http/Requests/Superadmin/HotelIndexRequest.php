<?php

namespace App\Http\Requests\Superadmin;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Superadmin\Concerns\FiltersBySuspension;

class HotelIndexRequest extends GenericIndexRequest
{
    use FiltersBySuspension;
}
