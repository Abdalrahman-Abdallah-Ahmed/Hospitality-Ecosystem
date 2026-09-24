<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Stays and the front-desk actions on them. Check-in and check-out take either
 * a stay (one room) or a reservation (all its rooms at once); both carry the
 * hotel the same-hotel check compares against.
 */
class StayPolicy
{
    use ChecksPermissions;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::STAYS_VIEW);
    }

    public function view(User $user, Stay $stay): bool
    {
        return $this->allows($user, Permission::STAYS_VIEW, $stay);
    }

    public function checkIn(User $user, Stay|Reservation $record): bool
    {
        return $this->allows($user, Permission::STAYS_CHECK_IN, $record);
    }

    public function checkOut(User $user, Stay|Reservation $record): bool
    {
        return $this->allows($user, Permission::STAYS_CHECK_OUT, $record);
    }
}
