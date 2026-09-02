<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\User;

class RecommendationPolicy
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
    public function view(User $user, Recommendation $recommendation): bool
    {
        return $user->isAdmin() && $recommendation->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can create models. When creating for a
     * specific reservation (e.g. triggering AI generation), that
     * reservation must also belong to the user's own hotel.
     */
    public function create(User $user, ?Reservation $reservation = null): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return $reservation === null || $reservation->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Recommendation $recommendation): bool
    {
        return $user->isAdmin() && $recommendation->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can record what happened to a recommendation.
     *
     * Deliberately wider than every other write ability here: employees are
     * the people standing at the desk when a guest says no, and a
     * refusal-capture instrument only admins can use will not capture
     * refusals. Still scoped to their own hotel.
     */
    public function recordOutcome(User $user, Recommendation $recommendation): bool
    {
        return ($user->isAdmin() || $user->isEmployee())
            && $recommendation->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Recommendation $recommendation): bool
    {
        return $user->isAdmin() && $recommendation->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Recommendation $recommendation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Recommendation $recommendation): bool
    {
        return false;
    }
}
