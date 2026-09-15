<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RecommendationPolicy
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
        return $this->allows($user, Permission::RECOMMENDATIONS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, Permission::RECOMMENDATIONS_VIEW, $recommendation);
    }

    /**
     * Determine whether the user can create models. When creating for a
     * specific reservation (e.g. triggering AI generation), that
     * reservation must also belong to the user's own hotel.
     */
    public function create(User $user, ?Reservation $reservation = null): bool
    {
        return $this->allows($user, Permission::RECOMMENDATIONS_GENERATE, $reservation);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, Permission::RECOMMENDATIONS_UPDATE, $recommendation);
    }

    /**
     * Determine whether the user can record what happened to a recommendation.
     */
    public function recordOutcome(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, Permission::RECOMMENDATIONS_RECORD_OUTCOME, $recommendation);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, Permission::RECOMMENDATIONS_DELETE, $recommendation);
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
