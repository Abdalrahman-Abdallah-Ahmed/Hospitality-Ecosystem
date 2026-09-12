<?php

namespace App\Jobs\AiCost;

use App\Models\AiUsageLog;
use App\Models\HotelGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Flags any account whose month-to-date AI cost has passed a configured share
 * of what it pays us.
 *
 * It ALERTS ONLY. It does not throttle, downgrade, or limit anything, and it
 * must not be extended to: a thin margin is a commercial conversation or a
 * misconfiguration, and neither is a decision a cron job should make about a
 * paying customer's service. The one thing in this phase that may act on its
 * own is AiSpendCeiling, and that exists to stop runaway loops, not to
 * manage margin.
 *
 * Accounts with no recorded contract value are skipped, not defaulted. We do
 * not know what they pay, so there is no ratio to compute — alerting on a
 * guessed denominator would train people to ignore the alert.
 */
class FlagAiCostOverrunsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @return array<int, array<string, mixed>> the accounts flagged
     */
    public function handle(): array
    {
        $threshold = (float) config('ai_cost.alert_margin_threshold');
        $rate = (float) config('ai_cost.fx.usd_per_eur');
        $flagged = [];

        $accounts = HotelGroup::query()
            ->whereNotNull('contract_value_monthly')
            ->where('contract_value_monthly', '>', 0)
            ->get();

        foreach ($accounts as $account) {
            $costUsd = (float) AiUsageLog::query()
                ->where('hotel_group_id', $account->getKey())
                ->where('occurred_at', '>=', now()->startOfMonth())
                ->sum('cost_usd');

            $currency = strtoupper($account->contract_currency ?? 'EUR');

            $cost = match ($currency) {
                'USD' => $costUsd,
                'EUR' => $costUsd / $rate,
                default => null,
            };

            if ($cost === null) {
                Log::warning('Cannot check AI margin for this account: no FX rate on file for its contract currency.', [
                    'hotel_group_id' => $account->getKey(),
                    'contract_currency' => $currency,
                ]);

                continue;
            }

            $value = (float) $account->contract_value_monthly;
            $share = $cost / $value;

            if ($share < $threshold) {
                continue;
            }

            $alert = [
                'hotel_group_id' => $account->getKey(),
                'account' => $account->name,
                'month_to_date_cost' => round($cost, 2),
                'contract_value' => round($value, 2),
                'currency' => $currency,
                'share_of_contract_pct' => round($share * 100, 1),
                'threshold_pct' => round($threshold * 100, 1),
                // Stated on the alert itself so nobody reads the number as
                // something an invoice produced.
                'basis' => 'manually recorded contract value; not invoiced',
                'action' => 'Alert only. Nothing has been throttled or limited.',
            ];

            Log::warning('AI cost has passed the configured share of this account\'s contract value.', $alert);

            $flagged[] = $alert;
        }

        return $flagged;
    }
}
