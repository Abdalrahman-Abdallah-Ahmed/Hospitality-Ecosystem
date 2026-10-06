<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BookingPolicy
{
    use ChecksPermissions;

    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::BOOKINGS_VIEW);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->allows($user, Permission::BOOKINGS_VIEW, $booking);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::BOOKINGS_CREATE);
    }

    /**
     * Moving a booking through its lifecycle — confirmed, realised, no-show,
     * cancelled.
     */
    public function updateStatus(User $user, Booking $booking): bool
    {
        return $this->allows($user, Permission::BOOKINGS_UPDATE_STATUS, $booking);
    }

    /**
     * Correcting a live booking's date, time, party or notes. Its guest,
     * origin and status are not editable through this, whatever is granted.
     */
    public function update(User $user, Booking $booking): bool
    {
        return $this->allows($user, Permission::BOOKINGS_UPDATE, $booking);
    }

    /**
     * Booking an activity past its daily capacity on purpose. Never a default.
     */
    public function overrideCapacity(User $user): bool
    {
        return $this->allows($user, Permission::BOOKINGS_OVERRIDE_CAPACITY);
    }

    // A booking is cancelled, never deleted — whatever permissions a role
    // grants. The history is the point, same as the ledger.
    public function delete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function restore(User $user, Booking $booking): bool
    {
        return false;
    }

    public function forceDelete(User $user, Booking $booking): bool
    {
        return false;
    }
}
