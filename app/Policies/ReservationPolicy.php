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
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_VIEW);
    }

    /**
     * Download the reservation list as a file (spec 021). Viewing it is not enough.
     */
    public function export(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_EXPORT);
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
     * Generate a voucher for the reservation, or preview a template with its
     * data (SPEC-013).
     */
    public function voucher(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_VOUCHER, $reservation);
    }

    /**
     * Email the reservation's voucher to the guest (SPEC-013).
     */
    public function voucherEmail(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_VOUCHER_EMAIL, $reservation);
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
     * Determine whether the user can mark, or undo, a no-show on a whole
     * reservation or on one of its rooms (SPEC-012). Room actions authorize
     * against the room's reservation.
     */
    public function noShow(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_NO_SHOW, $reservation);
    }

    /**
     * Determine whether the user can put physical rooms on a reservation's
     * lines, change or remove them, including upgrades, and see the arrivals
     * that still need a room (spec 020). Checked on top of create or update
     * when those name a room.
     */
    public function assignRoom(User $user, ?Reservation $reservation = null): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_ASSIGN_ROOM, $reservation);
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
