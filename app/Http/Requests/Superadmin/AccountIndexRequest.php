<?php

namespace App\Http\Requests\Superadmin;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Superadmin\Concerns\FiltersBySuspension;
use App\Models\HotelGroup;

class AccountIndexRequest extends GenericIndexRequest
{
    use FiltersBySuspension;

    /**
     * An account is a HotelGroup, so the name can't be inferred from the
     * controller.
     */
    protected function modelClass(): string
    {
        return HotelGroup::class;
    }

    /**
     * An allowlist, because filtering or sorting on a hidden column would
     * reveal its values.
     *
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        return ['id', 'name', 'slug', 'country_code', 'default_currency', 'default_timezone', 'created_at', 'updated_at'];
    }
}
