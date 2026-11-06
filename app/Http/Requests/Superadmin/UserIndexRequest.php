<?php

namespace App\Http\Requests\Superadmin;

use App\Http\Requests\Generic\GenericIndexRequest;

class UserIndexRequest extends GenericIndexRequest
{
    /**
     * An allowlist, because filtering or sorting on a secret column would
     * reveal its values.
     *
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        return [
            'id', 'name', 'email', 'role', 'phone_number', 'team_id', 'hotel_id', 'hotel_group_id',
            'group_role', 'staff_role_id', 'email_verified_at', 'created_at', 'updated_at',
        ];
    }
}
