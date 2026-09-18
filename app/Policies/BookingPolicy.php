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

    // A booking is cancelled, never edited in place or deleted — whatever
    // permissions a role grants.
    public function update(User $user, Booking $booking): bool
    {
        return false;
    }

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
