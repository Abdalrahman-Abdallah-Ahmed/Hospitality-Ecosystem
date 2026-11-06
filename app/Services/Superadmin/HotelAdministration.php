<?php

namespace App\Services\Superadmin;

use App\Exceptions\DomainRuleException;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Services\Superadmin\Concerns\AppliesAuditedChanges;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * What a super admin does to a hotel: create or restore it, edit its platform
 * fields, move it between accounts and delete it. A hotel's own settings stay
 * with its admin (the tenant HotelController).
 */
class HotelAdministration
{
    use AppliesAuditedChanges;

    /**
     * A hotel with the slug of a soft-deleted one brings that hotel back, but
     * only inside the same account. Otherwise reusing a slug would hand another
     * customer's guests and reservations to this account.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(HotelGroup $account, array $attributes): Hotel
    {
        return TenantContext::withoutScope(fn () => DB::transaction(function () use ($account, $attributes) {
            $trashed = Hotel::onlyTrashed()->where('slug', $attributes['slug'])->first();

            if ($trashed && $trashed->hotel_group_id !== $account->getKey()) {
                throw new DomainRuleException('The slug has already been taken.', 422);
            }

            if ($trashed) {
                $trashed->restore();
                $trashed->update($attributes);

                EventLogger::recordPlatform('platform.hotel.created', $trashed, context: ['restored' => true]);

                return $trashed->refresh();
            }

            $hotel = Hotel::create([
                'timezone' => $account->default_timezone,
                'currency' => $account->default_currency,
                ...$attributes,
                'hotel_group_id' => $account->getKey(),
            ]);

            EventLogger::recordPlatform('platform.hotel.created', $hotel);

            return $hotel->refresh();
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Hotel $hotel, array $attributes): Hotel
    {
        return DB::transaction(function () use ($hotel, $attributes) {
            $destination = $attributes['hotel_group_id'] ?? $hotel->hotel_group_id;

            if ($destination !== $hotel->hotel_group_id) {
                $this->refuseMoveLeavingCrossAccountLinks($hotel);
            }

            if ($changes = $this->applyChanges($hotel, $attributes)) {
                EventLogger::recordPlatform('platform.hotel.updated', $hotel, $changes);
            }

            return $hotel;
        });
    }

    public function delete(Hotel $hotel): void
    {
        DB::transaction(function () use ($hotel) {
            $hotel->delete();

            EventLogger::recordPlatform('platform.hotel.deleted', $hotel);
        });
    }

    /**
     * Users attached only through their home hotel follow it to the new
     * account. Anyone with a link of their own would end up reaching across
     * two accounts, so those links have to go first.
     */
    private function refuseMoveLeavingCrossAccountLinks(Hotel $hotel): void
    {
        $homeUsers = User::query()->where('hotel_id', $hotel->getKey());

        if (DB::table('hotel_user')->where('hotel_id', $hotel->getKey())->exists()) {
            throw new DomainRuleException('This hotel has memberships. Remove them before moving it to another account.', 422);
        }

        if ((clone $homeUsers)->whereNotNull('hotel_group_id')->exists()) {
            throw new DomainRuleException('This hotel has staff attached to its account. Detach them before moving it to another account.', 422);
        }

        if (DB::table('hotel_user')->whereIn('user_id', (clone $homeUsers)->select('id'))->exists()) {
            throw new DomainRuleException('This hotel has staff with memberships in other hotels. Remove them before moving it to another account.', 422);
        }

        if ($hotel->owner_id && User::whereKey($hotel->owner_id)->value('hotel_id') !== $hotel->getKey()) {
            throw new DomainRuleException('This hotel is owned by someone based at another hotel. Change that before moving it to another account.', 422);
        }
    }
}
