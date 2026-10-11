<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Reading guest conversations (SPEC-082 FR-004). The model is the
 * laravel/ai package's, registered against this policy in
 * AppServiceProvider. Which conversations belong to the hotel is decided by
 * ConversationQuery, not here: a conversation has no hotel_id of its own.
 */
class ConversationPolicy
{
    use ChecksPermissions;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CONVERSATIONS_VIEW);
    }
}
