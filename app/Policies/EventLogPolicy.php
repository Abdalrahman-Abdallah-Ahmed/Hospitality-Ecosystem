<?php

namespace App\Policies;

use App\Models\EventLog;
use App\Models\User;

class EventLogPolicy
{
    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Determine whether the user can read the audit trail.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, EventLog $eventLog): bool
    {
        return $user->isAdmin() && $eventLog->hotel_id === $user->hotel?->id;
    }

    // The event log has no write API — it is populated only by RecordsEvents.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function delete(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function restore(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, EventLog $eventLog): bool
    {
        return false;
    }
}
