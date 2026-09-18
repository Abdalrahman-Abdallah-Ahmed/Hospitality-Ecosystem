<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\HotelPolicy;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class HotelPolicyPolicy
{
    use ChecksPermissions;

    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::HOTEL_POLICIES_VIEW);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::HOTEL_POLICIES_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HotelPolicy $hotelPolicy): bool
    {
        return $this->allows($user, Permission::HOTEL_POLICIES_UPDATE, $hotelPolicy);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, HotelPolicy $hotelPolicy): bool
    {
        return $this->allows($user, Permission::HOTEL_POLICIES_DELETE, $hotelPolicy);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, HotelPolicy $hotelPolicy): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, HotelPolicy $hotelPolicy): bool
    {
        return false;
    }
}
