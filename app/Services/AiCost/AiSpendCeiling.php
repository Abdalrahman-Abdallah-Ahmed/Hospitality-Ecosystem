<?php

namespace App\Services\AiCost;

use App\Enums\AiTriggerKind;
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
     * Guest-driven calls also answer to a lower ceiling of their own. Anyone
     * who can message the hotel's number can cause guest spend, and the
     * account ceiling alone would let one hostile sender use up the whole
     * day and take the staff advisor and scheduled insights down with it.
     * Stopping guest traffic at a share of the ceiling keeps the rest for
     * the hotel's own work.
     *
     * @throws AiSpendCeilingExceededException
     */
    public function assertNotExceeded(?HotelGroup $account, ?AiTriggerKind $kind = null): void
    {
        if (! $account) {
            return;
        }

        $ceiling = config('ai_cost.daily_ceiling_usd');

        if ($ceiling === null) {
            return;
        }

        $this->assertUnder($account, $this->spentToday($account), (float) $ceiling, 'account');

        $guestShare = config('ai_cost.guest_share_of_daily_ceiling');

        if ($kind === AiTriggerKind::GUEST_MESSAGE && $guestShare !== null) {
            $this->assertUnder(
                $account,
                $this->spentToday($account, AiTriggerKind::GUEST_MESSAGE),
                (float) $ceiling * (float) $guestShare,
                'guest-driven',
            );
        }
    }

    /**
     * Measured spend only. An unpriced or estimated row contributes whatever
     * it was recorded as, which for an unpriced model is zero — the ceiling
     * deliberately does not extrapolate, because it stops service, and a
     * guess is not grounds for that.
     *
     * A half-open range rather than whereDate(), so the
     * (hotel_group_id, occurred_at) index serves the whole lookup; this runs
     * before every top-level AI call.
     */
    public function spentToday(HotelGroup $account, ?AiTriggerKind $kind = null): float
    {
        return (float) AiUsageLog::query()
            ->where('hotel_group_id', $account->getKey())
            ->where('occurred_at', '>=', today())
            ->where('occurred_at', '<', today()->addDay())
            ->when($kind, fn ($query) => $query->where('trigger_kind', $kind))
            ->sum('cost_usd');
    }

    /**
     * @throws AiSpendCeilingExceededException
     */
    private function assertUnder(HotelGroup $account, float $spentToday, float $ceiling, string $scope): void
    {
        if ($spentToday < $ceiling) {
            return;
        }

        Log::critical("AI daily {$scope} cost ceiling reached; further AI calls of this kind are stopped for today.", [
            'hotel_group_id' => $account->getKey(),
            'account' => $account->name,
            'spent_today_usd' => $spentToday,
            'ceiling_usd' => $ceiling,
        ]);

        throw new AiSpendCeilingExceededException($account, $spentToday, $ceiling);
    }
}
