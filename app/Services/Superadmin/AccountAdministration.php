<?php

namespace App\Services\Superadmin;

use App\Exceptions\DomainRuleException;
use App\Models\HotelGroup;
use App\Services\Superadmin\Concerns\AppliesAuditedChanges;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Facades\DB;

/**
 * What a super admin does to an account. Every write records one platform
 * event, which no hotel can read.
 */
class AccountAdministration
{
    use AppliesAuditedChanges;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): HotelGroup
    {
        return DB::transaction(function () use ($attributes) {
            $account = HotelGroup::create($attributes);

            EventLogger::recordPlatform('platform.account.created', $account);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(HotelGroup $account, array $attributes): HotelGroup
    {
        return DB::transaction(function () use ($account, $attributes) {
            if ($changes = $this->applyChanges($account, $attributes)) {
                EventLogger::recordPlatform('platform.account.updated', $account, $changes);
            }

            return $account;
        });
    }

    public function delete(HotelGroup $account): void
    {
        DB::transaction(function () use ($account) {
            if ($account->hotels()->exists()) {
                throw new DomainRuleException('An account with live hotels cannot be deleted.', 422);
            }

            $account->delete();

            EventLogger::recordPlatform('platform.account.deleted', $account);
        });
    }
}
