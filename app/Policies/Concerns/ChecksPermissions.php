<?php

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait ChecksPermissions
{
    /**
     * Whether the user holds the permission and, when a record is given, the
     * record belongs to the user's own hotel. A user with no hotel matches no
     * record.
     */
    protected function allows(User $user, Permission $permission, ?Model $record = null): bool
    {
        if (! $user->hasPermission($permission)) {
            return false;
        }

        return $record === null || $record->getAttribute('hotel_id') === $user->hotel?->id;
    }
}
