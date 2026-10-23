<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReservationImport;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Import history and reports are read with the permission that starts an
 * import (FR-031): whoever may import may see what imports did.
 */
class ReservationImportPolicy
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
        return $this->allows($user, Permission::RESERVATIONS_IMPORT);
    }

    public function view(User $user, ReservationImport $import): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_IMPORT, $import);
    }
}
