<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
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
        return $this->managesUser($user, $model) && $this->mayChange($user, $model);
    }

    /**
     * Determine whether the user can delete the model. An owner is never
     * deleted from here: a super admin deletes users through /api/superadmin,
     * once the owner role is cleared.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->managesUser($user, $model) && $this->mayChange($user, $model) && ! $model->isOwner();
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
     * An admin manages themselves and the non-super-admin users whose home is
     * the hotel the request works in. Themselves wherever they work, since
     * the index lists them in any hotel.
     *
     * The null guard is load-bearing: without it an admin with no hotel
     * matches every other hotel-less user, and super admins have no hotel.
     */
    private function managesUser(User $user, User $model): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if ($user->is($model)) {
            return true;
        }

        $hotelId = $user->hotel?->id;

        return ! $model->isSuperAdmin()
            && $hotelId !== null
            && $model->hotel_id === $hotelId;
    }

    /**
     * Only the account owner changes another admin, or a user who reaches a
     * hotel the acting admin can't. Otherwise an admin could reset that
     * user's password and sign in as them, gaining their hotels.
     */
    private function mayChange(User $user, User $model): bool
    {
        if ($user->is($model) || $user->isOwner()) {
            return true;
        }

        if ($model->isAdmin()) {
            return false;
        }

        return array_diff($model->accessibleHotelIds() ?? [], $user->reachableHotelIds() ?? []) === [];
    }
}
