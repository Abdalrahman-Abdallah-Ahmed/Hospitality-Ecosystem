<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ActivityCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ActivityCategoryPolicy
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
        return $this->allows($user, Permission::ACTIVITY_CATEGORIES_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ActivityCategory $activityCategory): bool
    {
        return $this->allows($user, Permission::ACTIVITY_CATEGORIES_VIEW, $activityCategory);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ACTIVITY_CATEGORIES_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ActivityCategory $activityCategory): bool
    {
        return $this->allows($user, Permission::ACTIVITY_CATEGORIES_UPDATE, $activityCategory);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ActivityCategory $activityCategory): bool
    {
        return $this->allows($user, Permission::ACTIVITY_CATEGORIES_DELETE, $activityCategory);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ActivityCategory $activityCategory): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ActivityCategory $activityCategory): bool
    {
        return false;
    }
}
