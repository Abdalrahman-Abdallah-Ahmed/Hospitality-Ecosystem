<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelGroup;
use App\Services\Metering\UsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What every account used, per feature, over a date range.
 *
 * The cross-account view. A hotel-group admin reading their own consumption
 * uses /api/usage instead, which shares this aggregation through UsageReport
 * but resolves its account from the token rather than looping every group.
 *
 * This phase observes; it does not enforce. Nothing here gates, blocks, or
 * limits a request, and there are deliberately no 402 responses — enforcement
 * is WP-9 and is deferred.
 */
class UsageController extends Controller
{
    public function index(Request $request, UsageReport $report)
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

        // Two grouped queries for the whole system, not two per account.
        $totals = $report->totals($from, $to);
        $seats = $report->seats($to);

        $accounts = HotelGroup::query()
            ->orderBy('name')
            ->get()
            ->map(fn (HotelGroup $account) => $report->describe(
                $account,
                $totals->get($account->id) ?? collect(),
                $seats->get($account->id) ?? collect(),
            ))
            ->values();

        return apiResponse('Usage fetched successfully.', 200, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'accounts' => $accounts,
            'not_measured' => $report->notMeasured(),
        ]);
    }
}
