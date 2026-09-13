<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
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
    public function view(User $user, User $model): bool
    {
        return $this->managesUser($user, $model);
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
    public function update(User $user, User $model): bool
    {
        return $this->managesUser($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->managesUser($user, $model);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * An admin manages the non-super-admin users of their own hotel.
     *
     * The null guard is load-bearing: without it an admin with no hotel
     * matches every other hotel-less user, and super admins have no hotel.
     */
    private function managesUser(User $user, User $model): bool
    {
        $hotelId = $user->hotel?->id;

        return $user->isAdmin()
            && ! $model->isSuperAdmin()
            && $hotelId !== null
            && $model->hotel_id === $hotelId;
    }
}
