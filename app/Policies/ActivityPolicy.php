<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Employees can read activities as well as admins: a booking taken at the
     * desk (see BookingPolicy) is for an activity picked from this list.
     * Changing the catalogue stays with admins.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isEmployee();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Activity $activity): bool
    {
        return ($user->isAdmin() || $user->isEmployee())
            && $activity->hotel_id === $user->hotel?->id;
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
    public function update(User $user, Activity $activity): bool
    {
        return $user->isAdmin() && $activity->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Activity $activity): bool
    {
        return $user->isAdmin() && $activity->hotel_id === $user->hotel?->id;
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
