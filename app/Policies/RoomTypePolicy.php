<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RoomType;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RoomTypePolicy
{
    use ChecksPermissions;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ROOM_TYPES_VIEW);
    }

    public function view(User $user, RoomType $roomType): bool
    {
        return $this->allows($user, Permission::ROOM_TYPES_VIEW, $roomType);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ROOM_TYPES_CREATE);
    }

    public function update(User $user, RoomType $roomType): bool
    {
        return $this->allows($user, Permission::ROOM_TYPES_UPDATE, $roomType);
    }

    public function delete(User $user, RoomType $roomType): bool
    {
        return $this->allows($user, Permission::ROOM_TYPES_DELETE, $roomType);
    }

    /**
     * Looking up how many rooms of each type are free. Availability has no
     * model of its own; it is about room types, so the ability lives here.
     */
    public function viewAvailability(User $user): bool
    {
        return $this->allows($user, Permission::AVAILABILITY_VIEW);
    }

    public function restore(User $user, RoomType $roomType): bool
    {
        return false;
    }

    public function forceDelete(User $user, RoomType $roomType): bool
    {
        return false;
    }
}
