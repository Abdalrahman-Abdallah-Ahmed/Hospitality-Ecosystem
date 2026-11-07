<?php

namespace App\Http\Resources\Superadmin;

use App\Http\Resources\HotelResource as TenantHotelResource;
use Illuminate\Http\Request;

/**
 * A hotel as a super admin sees it: everything its staff see, plus its
 * suspension. The tenant resource never carries these fields, because the
 * reason isn't for hotel staff.
 */
class HotelResource extends TenantHotelResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'suspended_at' => $this->suspended_at,
            'suspended_by' => $this->suspended_by,
            'suspended_reason' => $this->suspended_reason,
            // Its own fields stay empty when only its account is suspended.
            'is_suspended' => $this->resource->isSuspended(),
        ];
    }
}
