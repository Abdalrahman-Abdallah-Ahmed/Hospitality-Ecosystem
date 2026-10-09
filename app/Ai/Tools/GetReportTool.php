<?php

namespace App\Ai\Tools;

use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Models\AiInsights;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Metering\UsageReport;
use App\Services\Reports\ConversionReport;
use App\Services\Reports\DashboardSummary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The hotel's reports for the Admin AI, from the same services as the report
 * screens (SPEC-055 R4, R8). Each report needs the permission its screen
 * needs, so the tool checks per report rather than leaving it to the guard
 * (the one `selfChecked` entry in AdminToolset).
 *
 * Never shown here: AI provider cost or margin, and any figure read from the
 * transaction ledger (FR-021).
 */
class GetReportTool implements Tool
{
    public const REPORTS = ['dashboard', 'occupancy', 'conversion', 'insights', 'usage'];

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Hotel reports. "dashboard": today\'s arrivals, departures, VIPs in house, open tasks, occupancy and '
            .'booking value. "occupancy": occupied rooms per night from "from" to "to" (YYYY-MM-DD, at most 31 nights). '
            .'"conversion": how recommendations turned into bookings for a period. "insights": the latest AI insights. '
            .'"usage": AI usage counts for the account (no costs). Figures come only from here; never estimate them.';
    }

    public function handle(Request $request): Stringable|string
    {
        $report = $request->string('report')->toString();

        if (! in_array($report, self::REPORTS, true)) {
            return 'Ask for one of these reports: '.implode(', ', self::REPORTS).'.';
        }

        if ($refusal = $this->refusal($report)) {
            return $refusal;
        }

        try {
            $body = match ($report) {
                'dashboard' => $this->dashboard($request),
                'occupancy' => $this->occupancy($request),
                'conversion' => $this->conversion($request),
                'insights' => $this->insights(),
                'usage' => $this->usage($request),
            };
        } catch (DomainRuleException $e) {
            return $e->getMessage();
        }

        return json_encode(['report' => $report, ...$body], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->enum(self::REPORTS)->required(),
            'date' => $schema->string()->description('For "dashboard": the date occupancy is for, YYYY-MM-DD. Default today.'),
            'from' => $schema->string()->description('Start date, YYYY-MM-DD (occupancy, conversion, usage).'),
            'to' => $schema->string()->description('End date, YYYY-MM-DD (occupancy, conversion, usage).'),
        ];
    }

    /**
     * The permission each report's screen requires; usage is admin-only.
     */
    private function refusal(string $report): ?string
    {
        $user = User::find($this->user->id);

        $allowed = match ($report) {
            'dashboard', 'occupancy' => $user?->hasPermission(Permission::DASHBOARD_VIEW),
            'conversion' => $user?->hasPermission(Permission::RECOMMENDATIONS_VIEW),
            'insights' => $user?->hasPermission(Permission::AI_INSIGHTS_VIEW),
            'usage' => $user && ($user->isAdmin() || $user->isSuperAdmin()),
        };

        return $allowed ? null : "You do not have permission to see the {$report} report. Nothing was read.";
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(Request $request): array
    {
        $figures = app(DashboardSummary::class)->for(
            $this->hotel,
            $request->filled('date') ? Carbon::parse($request->string('date')->toString()) : null,
            $this->hotelToday(),
        );

        return [
            'pending_tasks' => $figures['pending_tasks'],
            'in_progress_tasks' => $figures['in_progress_tasks'],
            'arrivals_today' => $figures['today_arrivals']->map(fn (Reservation $r) => $r->reservation_id)->values()->all(),
            'departures_today' => $figures['today_departures']->map(fn (Reservation $r) => $r->reservation_id)->values()->all(),
            'vip_guests_in_house' => $figures['vip_guests']->map(fn ($guest) => trim($guest->first_name.' '.$guest->last_name))->values()->all(),
            'occupancy' => $figures['occupancy'],
            'booking_value_today' => (float) $figures['booking_value_today'],
            'room_revenue_today' => (float) $figures['room_revenue_today'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function occupancy(Request $request): array
    {
        $from = $request->string('from')->toString() ?: ($request->string('date')->toString() ?: $this->hotelToday());
        $to = $request->string('to')->toString() ?: $from;

        return ['nights' => app(DashboardSummary::class)->occupancy($this->hotel, $from, $to, $this->hotelToday())];
    }

    /**
     * The hotel's own date: what "today" means to the admin (R6).
     */
    private function hotelToday(): string
    {
        return now($this->hotel->timezone ?: config('app.timezone'))->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversion(Request $request): array
    {
        $report = app(ConversionReport::class);
        [$from, $to] = $report->period(
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        );

        return $report->for($from, $to, includeLedger: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function insights(): array
    {
        return [
            'insights' => AiInsights::query()
                ->where('hotel_id', $this->hotel->id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (AiInsights $insight) => [
                    'title' => $insight->title,
                    'description' => $insight->description,
                    'category' => $insight->category,
                    'evidence_level' => $insight->evidence_level?->value,
                    'created_at' => $insight->created_at?->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The account's usage exactly as GET /api/usage reports it to a hotel
     * admin: counts only, never cost.
     *
     * @return array<string, mixed>
     */
    private function usage(Request $request): array
    {
        $account = $this->user->account();

        if (! $account instanceof HotelGroup) {
            throw new DomainRuleException('This hotel does not belong to an account, so there is no usage to report.');
        }

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->endOfDay() : now()->endOfDay();
        $report = app(UsageReport::class);

        $described = $report->describe(
            $account,
            $report->totals($from, $to, $account->getKey())->get($account->getKey()) ?? collect(),
            $report->seats($to, $account->getKey())->get($account->getKey()) ?? collect(),
        );

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'seats' => $described['seats'],
            'features' => $described['features'],
            'recommendations' => $described['recommendations'],
        ];
    }
}
