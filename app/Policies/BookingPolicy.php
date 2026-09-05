<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isEmployee();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->worksHere($user, $booking);
    }

    /**
     * Employees can take bookings as well as admins: the person at the desk
     * when a guest asks for the sunset cruise is the one who should record it.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isEmployee();
    }

    /**
     * Moving a booking through its lifecycle — confirmed, realised, no-show,
     * cancelled. Only the outlet knows whether the guest actually turned up,
     * so this has to reach the same people who serve them.
     */
    public function updateStatus(User $user, Booking $booking): bool
    {
        return $this->worksHere($user, $booking);
    }

    // A booking is cancelled, never edited in place or deleted.
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

    private function worksHere(User $user, Booking $booking): bool
    {
        return ($user->isAdmin() || $user->isEmployee())
            && $booking->hotel_id === $user->hotel?->id;
    }
}
