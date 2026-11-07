<?php

namespace App\Support\Suspension;

use App\Enums\SuspensionScope;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which of a user's hotels are suspended, and whether that leaves them refused.
 * Login and EnsureNotSuspended both read it, so they cannot disagree.
 *
 * Decided per hotel rather than per account: stage 2 refuses links across
 * accounts, so this normally gives the account's answer, and if such a link
 * exists anyway a user is never refused over the wrong account.
 */
class HotelAccess
{
    /**
     * @param  Collection<string, Hotel>  $removed  suspended hotels, keyed by id
     * @param  list<string>  $activeIds  sorted, so the first is the same hotel on
     *                                   every request and at login
     */
    private function __construct(
        public readonly Collection $removed,
        public readonly array $activeIds,
        private readonly ?SuspensionScope $refusal,
    ) {}

    /**
     * One query on the normal path. One more only for a user with no hotels,
     * to look up their account.
     *
     * @param  list<string>  $hotelIds
     */
    public static function for(array $hotelIds, User $user): self
    {
        $hotels = $hotelIds === [] ? collect() : Hotel::query()
            ->select(['hotels.id', 'hotels.hotel_group_id', 'hotels.suspended_at'])
            ->withSuspension()
            ->whereIn('hotels.id', $hotelIds)
            ->get();

        if ($hotels->isEmpty()) {
            return new self(collect(), [], $user->account(withTrashed: true)?->isSuspended() ? SuspensionScope::ACCOUNT : null);
        }

        $removed = $hotels->filter(fn (Hotel $hotel) => $hotel->isSuspended())->keyBy('id');
        $activeIds = $hotels->pluck('id')->reject(fn (string $id) => $removed->has($id))->sort()->values()->all();

        $refusal = null;

        if ($activeIds === []) {
            $refusal = $removed->contains(fn (Hotel $hotel) => $hotel->accountIsSuspended())
                ? SuspensionScope::ACCOUNT
                : SuspensionScope::HOTEL;
        }

        return new self($removed, $activeIds, $refusal);
    }

    /**
     * Set when the user has nothing left to work in.
     */
    public function refusal(): ?SuspensionScope
    {
        return $this->refusal;
    }

    public function anyRemoved(): bool
    {
        return $this->removed->isNotEmpty();
    }

    /**
     * The scope to refuse with when this hotel is asked for by name. Null for
     * a hotel that isn't suspended or isn't one of the user's.
     */
    public function refusalFor(?string $hotelId): ?SuspensionScope
    {
        $hotel = $hotelId === null ? null : $this->removed->get($hotelId);

        return $hotel ? SuspendedResponse::scopeOf($hotel) : null;
    }
}
