<?php

namespace App\Services\AiCost;

use App\Models\AiUsageLog;
use App\Models\HotelGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each account's AI cost us over a window, in USD, for the super admin
 * dashboard. The detail behind these figures is on GET /api/superadmin/ai-cost,
 * and the two must agree.
 */
class AiCostReport
{
    /**
     * One grouped query. Money spent with no live account behind it stays
     * visible, as `unattributed` and `deleted_accounts`: it was spent either way.
     *
     * @return array{accounts: array<string, array<string, mixed>>, unattributed: array<string, mixed>, deleted_accounts: array<string, mixed>}
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        [$rows, $deletedIds] = TenantContext::withoutScope(fn () => [
            AiUsageLog::query()
                ->whereBetween('occurred_at', [$from, $to])
                ->groupBy('hotel_group_id')
                ->selectRaw('hotel_group_id, COUNT(*) AS calls, SUM(cost_usd) AS cost, COUNT(*) FILTER (WHERE cost_is_estimated) AS estimated')
                ->toBase()
                ->get(),
            HotelGroup::onlyTrashed()->pluck('id')->flip(),
        ]);

        [$unattributed, $attributed] = $rows->partition(fn (object $row) => $row->hotel_group_id === null);
        [$deleted, $live] = $attributed->partition(fn (object $row) => $deletedIds->has($row->hotel_group_id));

        return [
            'accounts' => $live->mapWithKeys(fn (object $row) => [
                $row->hotel_group_id => $this->figures((int) $row->calls, (float) $row->cost, (int) $row->estimated),
            ])->all(),
            'unattributed' => $this->totalled($unattributed),
            'deleted_accounts' => $this->totalled($deleted),
        ];
    }

    /**
     * How much of a figure was measured and how much was estimated. An estimate
     * for a model with no price on file reads zero, which must never be taken
     * to mean free, so a cost figure always travels with this.
     *
     * @return array{cost_basis: string, estimated_rows_pct: float}
     */
    public static function basis(int $total, int $estimated): array
    {
        return [
            'cost_basis' => match (true) {
                $total === 0 => 'no data',
                $estimated === 0 => 'measured',
                $estimated === $total => 'estimated',
                default => 'mixed',
            },
            'estimated_rows_pct' => $total === 0 ? 0.0 : round(($estimated / $total) * 100, 1),
        ];
    }

    /**
     * Provider cost, kept to the same six decimals the column stores.
     *
     * Two decimals is the obvious choice and it is wrong here, for exactly
     * the reason the column is decimal(12,6) in the first place: a single
     * call may cost $0.000420, and a month of a small account's traffic can
     * total well under a cent. Rounding money to cents at read time destroys
     * the cost base just as thoroughly as storing it that way would — the
     * report would say a hotel cost nothing to serve, which is never true of
     * a hotel that made calls.
     *
     * Contract figures are rounded to 2 instead, because those were agreed
     * by a person in whole cents and no precision beyond that exists.
     */
    public static function money(float $amount): float
    {
        return round($amount, 6);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function totalled(Collection $rows): array
    {
        $calls = (int) $rows->sum('calls');

        return [
            'calls' => $calls,
            ...$this->figures($calls, (float) $rows->sum('cost'), (int) $rows->sum('estimated')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(int $calls, float $cost, int $estimated): array
    {
        return ['ai_cost_usd' => self::money($cost), ...self::basis($calls, $estimated)];
    }
}
