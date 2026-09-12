<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiTriggerKind;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\HotelGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each account cost us to serve.
 *
 * WP-8's /api/admin/usage answers what a customer used. This answers what we
 * paid to give it to them. The gap between the two is the only number that
 * says whether the business works, and until both existed it could not be
 * computed for any hotel in any month.
 *
 * Three reporting rules are enforced here rather than left to the reader:
 *
 *  1. Every figure carries its basis. A margin without provenance is exactly
 *     the confident-but-unverifiable number this whole framework exists to
 *     prevent, so cost_basis and estimated_rows_pct are always present.
 *  2. The FX rate used is always stated. Costs are stored in USD as charged
 *     and converted at read time, never at write time.
 *  3. Where no contract value is recorded, margin is null — not zero, and
 *     not a guess.
 */
class AiCostController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth();

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfMonth();

        $rows = AiUsageLog::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->get();

        $byAccount = $rows->groupBy('hotel_group_id');

        $accounts = HotelGroup::query()
            ->orderBy('name')
            ->get()
            ->map(fn (HotelGroup $account) => $this->describe(
                $account,
                $byAccount->get($account->id) ?? collect(),
                $from,
                $to,
            ))
            ->values();

        return apiResponse('AI cost fetched successfully.', 200, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency_note' => $this->fxNote(),
            'accounts' => $accounts,
            'unattributed' => $this->unattributed($byAccount->get(null) ?? collect()),
            'unpriced_models' => $this->unpricedModels($rows),
            'notes' => sprintf(
                'FX at %s USD/EUR (%s). Contract value is manually recorded, not invoiced — it is what someone agreed by hand, and no invoice exists behind it.',
                $this->rate(),
                config('ai_cost.fx.rate_note'),
            ),
        ]);
    }

    /**
     * @param  Collection<int, AiUsageLog>  $rows
     */
    private function describe(HotelGroup $account, Collection $rows, Carbon $from, Carbon $to): array
    {
        $costUsd = $this->sum($rows);
        $calls = $rows->count();

        return [
            'hotel_group_id' => $account->id,
            'account' => $account->name,
            'ai_cost_usd' => $this->money($costUsd),
            'ai_cost_eur' => $this->toEur($costUsd),
            'calls' => $calls,
            'cost_per_call_eur' => $calls === 0 ? null : $this->money($this->toEur($costUsd) / $calls),
            'by_trigger' => $this->byTrigger($rows),
            'by_model' => $this->by($rows, 'model'),
            'by_hotel' => $this->by($rows, 'hotel_id'),
            // What retries cost. A call that retried three times cost four
            // calls, and this is the part of the bill that bought nothing.
            'retry_cost_eur' => $this->toEur($this->sum($rows->where('attempt', '>', 1))),
            'failed_calls' => $rows->where('succeeded', false)->count(),
            ...$this->margin($account, $costUsd, $from, $to),
            ...$this->basis($rows),
        ];
    }

    /**
     * Cost split by what set it off — the split that separates spend we
     * control from spend guests drive. Every kind is present even at zero, so
     * the absence of guest-driven cost is visible rather than merely missing.
     *
     * @param  Collection<int, AiUsageLog>  $rows
     * @return array<string, float>
     */
    private function byTrigger(Collection $rows): array
    {
        $split = [];

        foreach (AiTriggerKind::cases() as $kind) {
            $split[$kind->value] = $this->toEur($this->sum($rows->where('trigger_kind', $kind)));
        }

        return $split;
    }

    /**
     * @param  Collection<int, AiUsageLog>  $rows
     * @return array<string, float>
     */
    private function by(Collection $rows, string $column): array
    {
        return $rows->whereNotNull($column)
            ->groupBy($column)
            ->map(fn (Collection $group) => $this->toEur($this->sum($group)))
            ->sortDesc()
            ->all();
    }

    /**
     * Margin, or an explicit null and the reason there is none.
     *
     * Two things can withhold it. There may be no recorded contract value, in
     * which case there is no revenue figure to subtract from — zero would be
     * a lie and a guess would be worse. Or the range may not be a whole
     * calendar month, in which case a monthly contract value cannot be
     * compared against it without inventing a proration nobody agreed to.
     *
     * @return array<string, mixed>
     */
    private function margin(HotelGroup $account, float $costUsd, Carbon $from, Carbon $to): array
    {
        $none = fn (string $reason) => [
            'contract_value' => null,
            'contract_currency' => $account->contract_currency,
            'gross_margin' => null,
            'gross_margin_pct' => null,
            'margin_basis' => null,
            'margin_unavailable_reason' => $reason,
        ];

        if ($account->contract_value_monthly === null) {
            return $none('No contract value recorded for this account. Margin is null rather than zero: we do not know what they pay, which is a different fact from them paying nothing.');
        }

        if (! $this->isWholeCalendarMonth($from, $to)) {
            return $none('The requested range is not a whole calendar month, and the contract value is monthly. Prorating it would invent a precision nobody agreed to.');
        }

        $currency = strtoupper($account->contract_currency ?? 'EUR');
        $cost = $this->convert($costUsd, $currency);

        if ($cost === null) {
            return $none("No FX rate on file for {$currency}; only USD and EUR can be converted. Cost is reported in USD above.");
        }

        $value = (float) $account->contract_value_monthly;

        return [
            'contract_value' => round($value, 2),
            'contract_currency' => $currency,
            'gross_margin' => round($value - $cost, 2),
            'gross_margin_pct' => $value <= 0.0 ? null : round((($value - $cost) / $value) * 100, 1),
            // Said plainly wherever margin appears: the revenue half of this
            // figure was typed in by a person, not invoiced by a system.
            'margin_basis' => 'manually recorded contract value; not invoiced',
            'margin_unavailable_reason' => null,
        ];
    }

    /**
     * How much of this figure was measured and how much was estimated.
     *
     * Measured cost and estimated cost are different facts and must never be
     * summed into an unlabelled total, so the total is labelled.
     *
     * @param  Collection<int, AiUsageLog>  $rows
     * @return array<string, mixed>
     */
    private function basis(Collection $rows): array
    {
        $total = $rows->count();
        $estimated = $rows->where('cost_is_estimated', true)->count();

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
     * Cost we incurred but cannot attribute to any account.
     *
     * Reported rather than dropped. Money we spent is money we spent, and a
     * row we cannot place is a gap worth seeing — it usually means a call
     * site that never declared its context.
     *
     * @param  Collection<int, AiUsageLog>  $rows
     * @return array<string, mixed>
     */
    private function unattributed(Collection $rows): array
    {
        $costUsd = $this->sum($rows);

        return [
            'calls' => $rows->count(),
            'ai_cost_usd' => $this->money($costUsd),
            'ai_cost_eur' => $this->toEur($costUsd),
            'note' => $rows->isEmpty()
                ? 'Every logged call was attributed to an account.'
                : 'These calls reached a provider without an account behind them. Usually a call site that did not open an AiCostContext; the cost is real either way.',
        ];
    }

    /**
     * Models whose calls were logged but could not be priced.
     *
     * Their cost reads zero, and a zero that means "not priced" must never be
     * read as a zero that means "free" — so it is named here. config/ai.php
     * supports providers whose prices differ more than thirtyfold, and a
     * model appearing in this list is how a switch nobody flagged becomes
     * visible before the invoice arrives.
     *
     * @param  Collection<int, AiUsageLog>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function unpricedModels(Collection $rows): array
    {
        return $rows->where('cost_is_estimated', true)
            ->where('succeeded', true)
            ->groupBy(fn (AiUsageLog $row) => $row->provider.'/'.$row->model)
            ->map(fn (Collection $group, string $key) => [
                'model' => $key,
                'calls' => $group->count(),
                'note' => 'Logged with an estimated cost of zero because no price was on file for this model on the day of the call. Add one with AiModelPrice::supersede().',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AiUsageLog>  $rows
     */
    private function sum(Collection $rows): float
    {
        return (float) $rows->sum(fn (AiUsageLog $row) => (float) $row->cost_usd);
    }

    private function isWholeCalendarMonth(Carbon $from, Carbon $to): bool
    {
        return $from->isSameDay($from->copy()->startOfMonth())
            && $to->isSameDay($to->copy()->endOfMonth())
            && $from->isSameMonth($to);
    }

    private function rate(): float
    {
        return (float) config('ai_cost.fx.usd_per_eur');
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
    private function money(float $amount): float
    {
        return round($amount, 6);
    }

    private function toEur(float $usd): float
    {
        return $this->money($usd / $this->rate());
    }

    /**
     * Convert a USD cost into the account's contract currency, or null when
     * we hold no rate for it.
     */
    private function convert(float $usd, string $currency): ?float
    {
        return match ($currency) {
            'USD' => $this->money($usd),
            'EUR' => $this->toEur($usd),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fxNote(): array
    {
        return [
            'costs_stored_in' => 'USD',
            'usd_per_eur' => $this->rate(),
            'converted_at' => 'read time',
            'source' => config('ai_cost.fx.rate_note'),
        ];
    }
}
