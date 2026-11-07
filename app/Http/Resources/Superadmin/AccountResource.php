<?php

namespace App\Http\Resources\Superadmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as a super admin sees it. Contract fields are neither shown nor
 * accepted in this version.
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency,
            'default_timezone' => $this->default_timezone,
            'hotels_count' => (int) $this->hotels_count,
            'users_count' => (int) $this->users_count,
            'suspended_at' => $this->suspended_at,
            'suspended_by' => $this->suspended_by,
            'suspended_reason' => $this->suspended_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
