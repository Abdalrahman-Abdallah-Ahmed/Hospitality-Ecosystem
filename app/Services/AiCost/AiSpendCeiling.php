<?php

namespace App\Services\AiCost;

use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\AiUsageLog;
use App\Models\HotelGroup;
use Illuminate\Support\Facades\Log;

/**
 * The abuse stop, and the only thing in this phase that acts on its own.
 *
 * The margin alert never throttles: a thin margin is a commercial
 * conversation, and no cron job should decide to degrade a paying customer's
 * service over one. This is a different question. Its purpose is stopping
 * runaway loops and hostile traffic — GuestConciergeAgent answers anyone who
 * can message the hotel's number, so anyone who can message the hotel's
 * number can cause spend.
 *
 * It is therefore set well above any plausible day of real use. Crossing it
 * should mean something is broken, not that business was good. It pairs with
 * the per-guest limits in P1-002 A-3: those bound one conversation, this
 * bounds a whole account.
 */
class AiSpendCeiling
{
    /**
     * Stop an account whose AI spend today has already passed the ceiling.
     *
     * @throws AiSpendCeilingExceededException
     */
    public function assertNotExceeded(?HotelGroup $account): void
    {
        if (! $account) {
            return;
        }

        $ceiling = config('ai_cost.daily_ceiling_usd');

        if ($ceiling === null) {
            return;
        }

        $spentToday = $this->spentToday($account);

        if ($spentToday < (float) $ceiling) {
            return;
        }

        Log::critical('AI daily cost ceiling reached; further AI calls for this account are stopped for today.', [
            'hotel_group_id' => $account->getKey(),
            'account' => $account->name,
            'spent_today_usd' => $spentToday,
            'ceiling_usd' => (float) $ceiling,
        ]);

        throw new AiSpendCeilingExceededException($account, $spentToday, (float) $ceiling);
    }

    /**
     * Measured spend only. An unpriced or estimated row contributes whatever
     * it was recorded as, which for an unpriced model is zero — the ceiling
     * deliberately does not extrapolate, because it stops service, and a
     * guess is not grounds for that.
     */
    public function spentToday(HotelGroup $account): float
    {
        return (float) AiUsageLog::query()
            ->where('hotel_group_id', $account->getKey())
            ->whereDate('occurred_at', today())
            ->sum('cost_usd');
    }
}
