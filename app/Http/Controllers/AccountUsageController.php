<?php

namespace App\Http\Controllers;

use App\Models\HotelGroup;
use App\Services\Metering\UsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What THIS account used — the tenant-facing half of usage reporting.
 *
 * The super-admin report at /api/admin/usage answers the same question for
 * every hotel group at once. This answers it for exactly one: the account the
 * caller belongs to.
 *
 * Two rules shape it, and both are about what it must never return:
 *
 *  1. The account comes from the authenticated user, never from the request.
 *     There is deliberately no `hotel_group_id` parameter and no way to ask
 *     about another account — a filter that can be supplied is a filter that
 *     can be changed, and the first person to try a different UUID would be
 *     reading another hotel's numbers.
 *  2. No cost, ever. `ai_usage_logs.cost_usd` is our cost of goods; an
 *     account that can see what serving it costs can compute our margin on
 *     its own contract. That is a commercial conversation, not a dashboard
 *     field. This controller does not touch that table.
 *
 * Like everything else in Phase 2, it observes. It does not gate, block, or
 * limit anything, and it returns no plan and no limit — there are no plans
 * yet (WP-6 is deferred), and showing a limit that nothing enforces teaches
 * people to trust a number that is not load-bearing.
 */
class AccountUsageController extends Controller
{
    public function index(Request $request, UsageReport $report)
    {
        $user = $request->user();

        // Account-level consumption is commercial information about the
        // account as a whole. An employee works in a hotel; they do not
        // represent the customer, so this is not theirs to read. Group-role
        // holders are included because that role exists precisely to grant
        // account-wide standing.
        if (! $user->isAdmin() && ! $user->isSuperAdmin() && ! $user->group_role) {
            return apiResponse('This action is unauthorized.', 403);
        }

        $account = $user->account();

        if (! $account instanceof HotelGroup) {
            return apiResponse('You do not belong to any account.', 403);
        }

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

        $totals = $report->totals($from, $to, $account->getKey());
        $seats = $report->seats($to, $account->getKey());

        return apiResponse('Usage fetched successfully.', 200, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            ...$report->describe(
                $account,
                $totals->get($account->getKey()) ?? collect(),
                $seats->get($account->getKey()) ?? collect(),
            ),
            'not_measured' => $report->notMeasured(),
        ]);
    }
}
