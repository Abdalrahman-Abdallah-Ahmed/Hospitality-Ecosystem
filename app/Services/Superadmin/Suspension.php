<?php

namespace App\Services\Superadmin;

use App\Exceptions\DomainRuleException;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the suspended_* columns. An account and a hotel each hold
 * their own suspension, and changing one never touches the other. What counts
 * as suspended is decided by Hotel::isSuspended(). State isn't cached, so a
 * change applies from the next request or job start.
 */
class Suspension
{
    public function suspendAccount(HotelGroup $account, string $reason, User $by): HotelGroup
    {
        return $this->suspend($account, $reason, $by, 'platform.account.suspended');
    }

    public function reactivateAccount(HotelGroup $account): HotelGroup
    {
        return $this->reactivate($account, 'platform.account.reactivated');
    }

    public function suspendHotel(Hotel $hotel, string $reason, User $by): Hotel
    {
        return $this->suspend($hotel, $reason, $by, 'platform.hotel.suspended');
    }

    public function reactivateHotel(Hotel $hotel): Hotel
    {
        return $this->reactivate($hotel, 'platform.hotel.reactivated');
    }

    /**
     * @template TModel of Hotel|HotelGroup
     *
     * @param  TModel  $record
     * @return TModel
     */
    private function suspend(Model $record, string $reason, User $by, string $event): Model
    {
        return DB::transaction(function () use ($record, $reason, $by, $event) {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->suspended_at !== null) {
                throw new DomainRuleException('This is already suspended.', 422);
            }

            $locked->forceFill([
                'suspended_at' => now(),
                'suspended_by' => $by->getKey(),
                'suspended_reason' => $reason,
            ])->save();

            EventLogger::recordPlatform($event, $locked, context: ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * @template TModel of Hotel|HotelGroup
     *
     * @param  TModel  $record
     * @return TModel
     */
    private function reactivate(Model $record, string $event): Model
    {
        return DB::transaction(function () use ($record, $event) {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->suspended_at === null) {
                throw new DomainRuleException('This is not suspended.', 422);
            }

            $locked->forceFill([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspended_reason' => null,
            ])->save();

            EventLogger::recordPlatform($event, $locked);

            return $locked;
        });
    }
}
