<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Hotel;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class HotelPolicy
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
     * Whether the user can list the hotels they work in. The list itself is
     * limited to those, in HotelController. Every other ability stays with the
     * hotel's owner: hotels.view opens only this.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::HOTELS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Hotel $hotel): bool
    {
        return $user->isAdmin() && $hotel->owner_id === $user->id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hotel $hotel): bool
    {
        return $user->isAdmin() && $hotel->owner_id === $user->id;
    }
}
