<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ActivityPolicy
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
        return $this->allows($user, Permission::ACTIVITIES_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Activity $activity): bool
    {
        return $this->allows($user, Permission::ACTIVITIES_VIEW, $activity);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ACTIVITIES_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Activity $activity): bool
    {
        return $this->allows($user, Permission::ACTIVITIES_UPDATE, $activity);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Activity $activity): bool
    {
        return $this->allows($user, Permission::ACTIVITIES_DELETE, $activity);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Activity $activity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Activity $activity): bool
    {
        return false;
    }
}
