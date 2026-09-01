<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Stay;
use Carbon\CarbonInterface;

/**
 * Decides which stay — and therefore which guest — a transaction belongs to.
 *
 * Transactions arrive tagged with a room number, not a guest. A room hosts
 * hundreds of guests over its life, so attributing a purchase to "whoever is
 * in that room now" credits an arriving guest with everything previous
 * occupants ever bought — measured at roughly 5x revenue inflation on this
 * data. The only safe rule is: the guest whose stay window actually contains
 * the moment of sale. If none does, the caller must leave the transaction
 * unattributed (and mark it L4), never guess.
 */
class TransactionAttributionService
{
    public function resolveStay(string $roomNumber, CarbonInterface $transactedAt, Hotel $hotel): ?Stay
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->whereHas('room', fn ($query) => $query->where('room_number', $roomNumber))
            ->where('checked_in_at', '<=', $transactedAt)
            ->where(fn ($query) => $query
                ->whereNull('checked_out_at')
                ->orWhere('checked_out_at', '>=', $transactedAt))
            ->orderByDesc('checked_in_at')
            ->first();
    }
}
