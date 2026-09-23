<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class ReservationPolicy
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
        return $this->allows($user, Permission::RESERVATIONS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_VIEW, $reservation);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_CREATE);
    }

    /**
     * Determine whether the user can bulk-import reservations from a file.
     */
    public function import(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_IMPORT);
    }

    /**
     * Determine whether the user can save a reservation that sells more rooms
     * of a type than are free (overbook_override). Checked on top of create
     * or update, and only when the override is asked for.
     */
    public function overbook(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_OVERBOOK);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_UPDATE, $reservation);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_DELETE, $reservation);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Reservation $reservation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Reservation $reservation): bool
    {
        return false;
    }
}
