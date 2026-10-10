<?php

namespace App\Policies;

use App\Models\ProactiveMessage;
use App\Models\User;

/**
 * The proactive message log is admin-only, like the hotel settings that
 * switch proactive messaging on: it has no permission of its own. Nobody
 * creates, edits or deletes its rows through the API.
 */
class ProactiveMessagePolicy
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
        return $user->isAdmin();
    }

    public function view(User $user, ProactiveMessage $message): bool
    {
        return $user->isAdmin() && $message->hotel_id === $user->hotel?->id;
    }
}
