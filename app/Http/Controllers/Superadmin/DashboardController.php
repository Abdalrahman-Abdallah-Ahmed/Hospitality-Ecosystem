<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\SuperadminDashboardRequest;
use App\Services\AiCost\AiCostReport;
use App\Services\Reports\PlatformSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The platform at a glance, one section per endpoint so that a slow section
 * doesn't hold up the others. The route guard is the authorization: this calls
 * no tenant policy.
 *
 * Figures can be up to 15 minutes old, and nothing clears them early. Each
 * response says when they were computed.
 */
class DashboardController extends Controller
{
    private const CACHE_SECONDS = 900;

    private const CURRENT_COUNTS_KEY = 'superadmin:dashboard:v1:counts:current';

    /**
     * Accounts, hotels and users don't depend on the window, so they get one
     * entry of their own. Cached per window, they could show a suspension in one
     * date range and not in another. The older of the two entries is the time to
     * report, so `cached_at` never overstates how fresh the figures are. ISO 8601
     * UTC strings sort by time.
     */
    public function counts(SuperadminDashboardRequest $request, PlatformSummary $summary): JsonResponse
    {
        $current = Cache::remember(self::CURRENT_COUNTS_KEY, self::CACHE_SECONDS, fn () => [
            ...$summary->currentCounts(),
            'cached_at' => now()->toIso8601ZuluString(),
        ]);

        $signups = $this->section('counts', $request, fn (Carbon $from, Carbon $to) => [
            'signups' => $summary->signups($from, $to),
        ]);

        return apiResponse('Dashboard counts fetched successfully.', 200, [
            ...$current,
            ...$signups,
            'cached_at' => min($current['cached_at'], $signups['cached_at']),
        ]);
    }

    /**
     * Cost is added here, not in PlatformSummary, because CostVisibilityTest
     * keeps it out of app/Services/Reports/.
     */
    public function usage(SuperadminDashboardRequest $request, PlatformSummary $summary, AiCostReport $cost): JsonResponse
    {
        return apiResponse('Dashboard usage fetched successfully.', 200, $this->section('usage', $request, function (Carbon $from, Carbon $to) use ($summary, $cost) {
            $costs = $cost->summary($from, $to);
            $noCost = ['ai_cost_usd' => 0.0, ...AiCostReport::basis(0, 0)];

            return [
                'accounts' => array_map(
                    fn (array $account) => [...$account, ...($costs['accounts'][$account['hotel_group_id']] ?? $noCost)],
                    $summary->usage($from, $to),
                ),
                'unattributed' => $costs['unattributed'],
                'deleted_accounts' => $costs['deleted_accounts'],
            ];
        }));
    }

    public function activity(SuperadminDashboardRequest $request, PlatformSummary $summary): JsonResponse
    {
        return apiResponse('Dashboard activity fetched successfully.', 200, $this->section('activity', $request, fn (Carbon $from, Carbon $to) => [
            'hotels' => $summary->activity($from, $to),
        ]));
    }

    /**
     * The cached value must stay plain arrays and scalars: with
     * `cache.serializable_classes` off, an object comes back from the cache as
     * an incomplete class.
     *
     * @return array<string, mixed>
     */
    private function section(string $section, SuperadminDashboardRequest $request, callable $compute): array
    {
        $from = $request->from();
        $to = $request->to();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        return Cache::remember(
            "superadmin:dashboard:v1:{$section}:{$fromDate}:{$toDate}",
            self::CACHE_SECONDS,
            fn () => [
                ...$compute($from, $to),
                'from' => $fromDate,
                'to' => $toDate,
                'cached_at' => now()->toIso8601ZuluString(),
            ],
        );
    }
}
