<?php

namespace App\Policies;

use App\Models\TaskCategory;
use App\Models\User;

class TaskCategoryPolicy
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
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TaskCategory $taskCategory): bool
    {
        return $user->isAdmin() && $taskCategory->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TaskCategory $taskCategory): bool
    {
        return $user->isAdmin() && $taskCategory->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TaskCategory $taskCategory): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TaskCategory $taskCategory): bool
    {
        return false;
    }
}
