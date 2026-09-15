<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class AiInsightsPolicy
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
        return $this->allows($user, Permission::AI_INSIGHTS_VIEW);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::AI_INSIGHTS_GENERATE);
    }
}
