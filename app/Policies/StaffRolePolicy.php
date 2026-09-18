<?php

namespace App\Policies;

use App\Models\StaffRole;
use App\Models\User;

/**
 * Staff roles are admin-only and have no permission of their own: an employee
 * who could edit roles could grant themselves anything.
 */
class StaffRolePolicy
{
    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, StaffRole $staffRole): bool
    {
        return $this->managesRole($user, $staffRole);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, StaffRole $staffRole): bool
    {
        return $this->managesRole($user, $staffRole);
    }

    public function delete(User $user, StaffRole $staffRole): bool
    {
        return $this->managesRole($user, $staffRole);
    }

    public function restore(User $user, StaffRole $staffRole): bool
    {
        return false;
    }

    public function forceDelete(User $user, StaffRole $staffRole): bool
    {
        return false;
    }

    private function managesRole(User $user, StaffRole $staffRole): bool
    {
        return $user->isAdmin() && $staffRole->hotel_id === $user->hotel?->id;
    }
}
