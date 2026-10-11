<?php

namespace App\Services\Metering;

use App\Enums\AiAgentName;
use App\Models\AiUsageLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How much AI an account used — requests and tokens — and never what it cost.
 *
 * This is the only hotel-facing reader of ai_usage_logs (SPEC-091). That
 * table holds our cost of goods, so the guarantee is made by what the query
 * selects, not by what a caller remembers to strip later: the select list
 * below names counts and token sums only. Cost, the estimate flag, the model
 * and the provider are never read. The model is withheld as well as the cost
 * because model plus tokens is enough to look the price up.
 *
 * Calls are grouped by capability — the agent of the AI run they belong to —
 * so the turn classifier and a knowledge search inside a Concierge reply are
 * counted as Concierge work.
 */
class AiUsageSummary
{
    public const BASIS = 'Counted from every AI provider call made for this account. Calls made inside a Concierge turn (classification, knowledge search) are counted under Concierge.';

    /**
     * Grouped rows for one account, or every account when none is named.
     *
     * Cached input is not included in input_tokens (laravel/ai subtracts it
     * before handing usage over), so it is added back here: to a hotel it is
     * input like any other, and it only differs in price.
     *
     * @return Collection<string, Collection<int, object>> keyed by hotel_group_id
     */
    public function totals(Carbon $from, Carbon $to, ?string $accountId = null): Collection
    {
        return AiUsageLog::query()
            ->whereNotNull('hotel_group_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($accountId, fn ($query) => $query->where('hotel_group_id', $accountId))
            ->groupBy('hotel_group_id', 'hotel_id', 'capability')
            ->selectRaw('hotel_group_id, hotel_id, capability, COUNT(*) AS requests, COALESCE(SUM(input_tokens + cached_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->toBase()
            ->get()
            ->groupBy('hotel_group_id');
    }

    /**
     * One account's AI usage: totals, by capability and by hotel.
     *
     * Every reportable capability and every hotel of the account is listed,
     * at zero when nothing happened. Calls the breakdowns cannot place count
     * toward the totals and appear only when there are any — under the
     * capability `unattributed`, and under the hotel key `unassigned` (a call
     * logged with no hotel) — so each breakdown always adds up without a gap
     * being hidden. A hotel deleted or moved out since keeps its own row for
     * the calls it made.
     *
     * Hotels are read from the account's `hotels` relation, so a caller
     * describing many accounts can eager-load them in one query.
     *
     * @param  Collection<int, object>  $rows  this account's rows from totals()
     * @return array<string, mixed>
     */
    public function describe(HotelGroup $account, Collection $rows): array
    {
        $byCapability = [];

        foreach (AiAgentName::reportable() as $capability) {
            $byCapability[$capability->value] = ['label' => $capability->label(), ...$this->figures(collect())];
        }

        // Grouped by what each value maps to, so an unknown value adds to
        // the unattributed bucket instead of replacing it.
        $grouped = $rows->groupBy(fn (object $row) => (AiAgentName::tryFrom((string) $row->capability) ?? AiAgentName::UNATTRIBUTED)->value);

        foreach ($grouped as $capability => $group) {
            $byCapability[$capability] = ['label' => AiAgentName::from($capability)->label(), ...$this->figures($group)];
        }

        $byHotel = [];

        foreach ($account->hotels->sortBy('name') as $hotel) {
            $byHotel[$hotel->id] = ['hotel' => $hotel->name, ...$this->figures($rows->where('hotel_id', $hotel->id))];
        }

        $elsewhere = $rows->whereNotNull('hotel_id')->whereNotIn('hotel_id', array_keys($byHotel));

        if ($elsewhere->isNotEmpty()) {
            $names = Hotel::withTrashed()->whereKey($elsewhere->pluck('hotel_id')->unique()->all())->pluck('name', 'id');

            foreach ($elsewhere->groupBy('hotel_id') as $hotelId => $group) {
                $byHotel[$hotelId] = ['hotel' => $names[$hotelId] ?? null, ...$this->figures($group)];
            }
        }

        $unassigned = $rows->whereNull('hotel_id');

        if ($unassigned->isNotEmpty()) {
            $byHotel['unassigned'] = ['hotel' => null, ...$this->figures($unassigned)];
        }

        return [
            ...$this->figures($rows),
            'by_capability' => $byCapability,
            'by_hotel' => $byHotel,
            'basis' => self::BASIS,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{requests: int, input_tokens: int, output_tokens: int, total_tokens: int}
     */
    private function figures(Collection $rows): array
    {
        $input = (int) $rows->sum('input_tokens');
        $output = (int) $rows->sum('output_tokens');

        return [
            'requests' => (int) $rows->sum('requests'),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
        ];
    }
}
