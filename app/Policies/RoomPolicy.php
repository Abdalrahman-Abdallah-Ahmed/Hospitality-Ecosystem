<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Room;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RoomPolicy
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
        return $this->allows($user, Permission::ROOMS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Room $room): bool
    {
        return $this->allows($user, Permission::ROOMS_VIEW, $room);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ROOMS_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Room $room): bool
    {
        return $this->allows($user, Permission::ROOMS_UPDATE, $room);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Room $room): bool
    {
        return $this->allows($user, Permission::ROOMS_DELETE, $room);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Room $room): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Room $room): bool
    {
        return false;
    }
}
