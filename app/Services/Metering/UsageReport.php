<?php

namespace App\Services\Metering;

use App\Enums\MeterFeature;
use App\Models\HotelGroup;
use App\Models\MeterEvent;
use App\Models\UsageCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How consumption is described, in one place.
 *
 * Two endpoints read it and they must never disagree. The super-admin report
 * answers "what did every account use", and the tenant report answers "what
 * did I use" — and if a hotel's own figure ever differs from the figure we
 * quote them from our side, the argument that follows is not one anybody
 * wins. Same events, same arithmetic, same words for what is not measured.
 *
 * The scoping is NOT shared, deliberately: each caller passes its own account
 * filter, because who is allowed to see what is the one decision that must be
 * made explicitly at each endpoint rather than inherited from a helper.
 *
 * Nothing here touches ai_usage_logs. Provider cost is our cost of goods and
 * belongs only to the super-admin cost report — a hotel that could see it
 * could compute our margin on their own contract.
 */
class UsageReport
{
    /**
     * Event-derived usage summed over a date range, grouped by account.
     *
     * Summed from the events rather than read from the counters: counters are
     * a cache keyed by whole periods, and these endpoints answer about an
     * arbitrary range. The events are the source of truth either way.
     *
     * @return Collection<string, Collection<int, MeterEvent>>
     */
    public function totals(Carbon $from, Carbon $to, ?string $accountId = null): Collection
    {
        return MeterEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->when($accountId, fn ($query) => $query->where('hotel_group_id', $accountId))
            ->groupBy('hotel_group_id', 'feature_code', 'unit')
            ->selectRaw('hotel_group_id, feature_code, unit, sum(quantity) as total')
            ->get()
            ->groupBy('hotel_group_id');
    }

    /**
     * Seats are not event-derived — they are "how many exist right now" — so
     * they come from the counter the recount writes rather than from a sum
     * over the range.
     *
     * @return Collection<string, Collection<int, UsageCounter>>
     */
    public function seats(Carbon $to, ?string $accountId = null): Collection
    {
        return UsageCounter::query()
            ->where('period_start', MeteringService::periodStart($to))
            ->whereIn('feature_code', array_map(fn (MeterFeature $f) => $f->value, MeterFeature::seats()))
            ->when($accountId, fn ($query) => $query->where('hotel_group_id', $accountId))
            ->get()
            ->groupBy('hotel_group_id');
    }

    /**
     * One account's consumption.
     *
     * @param  Collection<int, MeterEvent>  $totals
     * @param  Collection<int, UsageCounter>  $seats
     */
    public function describe(HotelGroup $account, Collection $totals, Collection $seats): array
    {
        $features = $totals->mapWithKeys(fn ($row) => [
            $row->feature_code->value => [
                'used' => (int) $row->total,
                'unit' => $row->unit,
            ],
        ])->all();

        return [
            'hotel_group_id' => $account->id,
            'account' => $account->name,
            'seats' => $seats->mapWithKeys(fn (UsageCounter $counter) => [
                $counter->feature_code->value => (int) $counter->used,
            ])->all(),
            'features' => $features,
            'recommendations' => $this->recommendationPair($features),
        ];
    }

    /**
     * Generated against delivered is the pair worth acting on: it separates
     * "our agent is weak" from "the hotel's staff never passed it on" — two
     * problems with two different owners.
     *
     * Delivered is reported as an explicit null with a reason rather than a
     * zero. A meter reading zero and a meter that does not exist are
     * different facts, and only one of them is the hotel's fault.
     */
    public function recommendationPair(array $features): array
    {
        return [
            'generated' => $features[MeterFeature::RECOMMENDATIONS_GENERATED->value]['used'] ?? 0,
            'delivered' => null,
            'delivered_basis' => 'not measured',
            'delivered_reason' => self::DELIVERED_NOT_MEASURED,
        ];
    }

    private const DELIVERED_NOT_MEASURED = 'Delivery is inferred, not measured: /api/analytics/conversion assumes a recommendation was delivered unless an outcome explicitly records not_delivered. Metering an assumption would put an estimate in a usage table. Needs the measured delivery timestamp from P1-002 A-1.';

    /**
     * Meters that are declared but have no source to record from yet.
     *
     * Named rather than omitted, so a consumer sees an explicit gap instead
     * of a silent zero.
     *
     * @return array<string, string>
     */
    public function notMeasured(): array
    {
        return [
            MeterFeature::RECOMMENDATIONS_DELIVERED->value => self::DELIVERED_NOT_MEASURED,
            MeterFeature::CONVERSATIONS_HANDLED->value => 'Needs a definition of when a conversation ends; agent_conversations has no status column.',
        ];
    }
}
