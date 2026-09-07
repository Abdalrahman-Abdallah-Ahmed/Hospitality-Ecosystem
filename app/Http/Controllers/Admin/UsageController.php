<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MeterFeature;
use App\Http\Controllers\Controller;
use App\Models\HotelGroup;
use App\Models\MeterEvent;
use App\Models\UsageCounter;
use App\Services\Metering\MeteringService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each account used, per feature, over a date range.
 *
 * This phase observes; it does not enforce. Nothing here gates, blocks, or
 * limits a request, and there are deliberately no 402 responses — enforcement
 * is WP-9 and is deferred.
 */
class UsageController extends Controller
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
            : now()->endOfDay();

        // Summed from the events, not read from the counters: counters are a
        // cache keyed by whole periods, and this endpoint answers about an
        // arbitrary range. The events are the source of truth either way.
        $totals = MeterEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('hotel_group_id', 'feature_code', 'unit')
            ->selectRaw('hotel_group_id, feature_code, unit, sum(quantity) as total')
            ->get()
            ->groupBy('hotel_group_id');

        // Seats are not event-derived, so they come from the counter the
        // recount writes rather than from a sum over the range.
        $seats = UsageCounter::query()
            ->where('period_start', MeteringService::periodStart($to))
            ->whereIn('feature_code', array_map(fn (MeterFeature $f) => $f->value, MeterFeature::seats()))
            ->get()
            ->groupBy('hotel_group_id');

        $accounts = HotelGroup::query()
            ->orderBy('name')
            ->get()
            ->map(fn (HotelGroup $account) => $this->describe(
                $account,
                $totals->get($account->id) ?? collect(),
                $seats->get($account->id) ?? collect(),
            ))
            ->values();

        return apiResponse('Usage fetched successfully.', 200, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'accounts' => $accounts,
            'not_measured' => $this->notMeasured(),
        ]);
    }

    /**
     * @param  Collection<int, MeterEvent>  $totals
     * @param  Collection<int, UsageCounter>  $seats
     */
    private function describe(HotelGroup $account, $totals, $seats): array
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
    private function recommendationPair(array $features): array
    {
        return [
            'generated' => $features[MeterFeature::RECOMMENDATIONS_GENERATED->value]['used'] ?? 0,
            'delivered' => null,
            'delivered_basis' => 'not measured',
            'delivered_reason' => 'Delivery is inferred, not measured: /api/analytics/conversion assumes a recommendation was delivered unless an outcome explicitly records not_delivered. Metering an assumption would put an estimate in a usage table. Needs the measured delivery timestamp from P1-002 A-1.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function notMeasured(): array
    {
        return [
            MeterFeature::RECOMMENDATIONS_DELIVERED->value => 'Delivery is inferred, not measured: /api/analytics/conversion assumes a recommendation was delivered unless an outcome explicitly records not_delivered. Metering an assumption would put an estimate in a usage table. Needs the measured delivery timestamp from P1-002 A-1.',
            MeterFeature::CONVERSATIONS_HANDLED->value => 'Needs a definition of when a conversation ends; agent_conversations has no status column.',
        ];
    }
}
