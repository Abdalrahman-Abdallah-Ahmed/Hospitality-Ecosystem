<?php

namespace App\Policies;

use App\Models\ActivityCategory;
use App\Models\User;

class ActivityCategoryPolicy
{
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
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ActivityCategory $activityCategory): bool
    {
        return $user->isAdmin() && $activityCategory->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ActivityCategory $activityCategory): bool
    {
        return $user->isAdmin() && $activityCategory->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ActivityCategory $activityCategory): bool
    {
        return $user->isAdmin() && $activityCategory->hotel_id === $user->hotel?->id;
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
