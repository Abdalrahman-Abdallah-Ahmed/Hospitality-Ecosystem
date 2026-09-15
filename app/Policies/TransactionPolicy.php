<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class TransactionPolicy
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
        return $this->allows($user, Permission::TRANSACTIONS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->allows($user, Permission::TRANSACTIONS_VIEW, $transaction);
    }

    /**
     * Determine whether the user can create models (used by the importer).
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::TRANSACTIONS_IMPORT);
    }

    /**
     * Determine whether the user can reverse the model. Reversal is the only
     * sanctioned correction — the ledger has no update or delete, whatever
     * permissions a role grants.
     */
    public function reverse(User $user, Transaction $transaction): bool
    {
        return $this->allows($user, Permission::TRANSACTIONS_REVERSE, $transaction);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function restore(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function forceDelete(User $user, Transaction $transaction): bool
    {
        return false;
    }
}
