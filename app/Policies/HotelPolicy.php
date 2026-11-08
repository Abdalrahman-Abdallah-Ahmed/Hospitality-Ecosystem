<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Hotel;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class HotelPolicy
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
     * Whether the user can list the hotels they work in. The list itself is
     * limited to those, in HotelController. Every other ability stays with
     * admins: hotels.view opens only this.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::HOTELS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Hotel $hotel): bool
    {
        return $this->runs($user, $hotel);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hotel $hotel): bool
    {
        return $this->runs($user, $hotel);
    }

    /**
     * An admin who can reach the hotel: the account owner for all of the
     * account, and any other admin for their home and member hotels.
     *
     * Reads every hotel the user may reach, not the narrowed tenant scope, so a
     * suspended hotel of theirs gets the suspended 403 from the controller,
     * and only a caller who can't reach it gets the plain one.
     *
     * isAdmin() on purpose: hotel settings stay admin-only whatever a staff
     * role grants (CLAUDE.md, access control rule 4), so there is no
     * permission case to check.
     */
    private function runs(User $user, Hotel $hotel): bool
    {
        return $user->isAdmin() && in_array($hotel->getKey(), $user->reachableHotelIds() ?? [], true);
    }
}
