<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * What the AI remembers about guests is protected guest data (SPEC-084
 * FR-019): employees see or delete it only through a staff role. Nobody
 * writes a memory by hand; extraction is the only writer.
 */
class GuestMemoryPolicy
{
    use ChecksPermissions;

    public function before(User $user, string $ability): ?bool
    {
        // Not even a super admin writes a memory by hand.
        if ($ability === 'create' || $ability === 'update') {
            return false;
        }

        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user, Guest $guest): bool
    {
        return $this->allows($user, Permission::GUEST_MEMORIES_VIEW, $guest);
    }

    public function delete(User $user, GuestMemory $memory): bool
    {
        return $this->allows($user, Permission::GUEST_MEMORIES_DELETE, $memory);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GuestMemory $memory): bool
    {
        return false;
    }
}
