<?php

namespace App\Services\Reports;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Metering\UsageReport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the super admin dashboard shows, across every account and hotel. Returns
 * plain arrays, because the caller caches them.
 *
 * Holds no cost data: CostVisibilityTest scans this directory, so AI cost lives
 * in App\Services\AiCost\AiCostReport and the controller adds it.
 */
class PlatformSummary
{
    public function __construct(
        private readonly UsageReport $usageReport,
    ) {}

    /**
     * Accounts, hotels and users as they are now. Suspension has no history, so
     * no window can apply to these.
     *
     * @return array{accounts: array<string, int>, hotels: array<string, int>, users: array<string, int>}
     */
    public function currentCounts(): array
    {
        return TenantContext::withoutScope(fn () => [
            'accounts' => [
                'active' => HotelGroup::query()->whereNull('hotel_groups.suspended_at')->count(),
                'suspended' => HotelGroup::query()->suspended()->count(),
            ],
            'hotels' => [
                'active' => Hotel::query()->notSuspended()->count(),
                'suspended' => Hotel::query()->suspended()->count(),
            ],
            'users' => $this->userCounts(),
        ]);
    }

    /**
     * One row per day of the window. A deleted account still signed up on the
     * day it was created. Stored timestamps are UTC, so DATE() gives the UTC day.
     *
     * @return list<array{date: string, accounts: int, users: int}>
     */
    public function signups(Carbon $from, Carbon $to): array
    {
        return TenantContext::withoutScope(function () use ($from, $to) {
            $accounts = $this->createdPerDay(HotelGroup::withTrashed(), $from, $to);
            $users = $this->createdPerDay($this->usersWhoCount(), $from, $to);

            $days = [];

            foreach (CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()) as $day) {
                $date = $day->toDateString();

                $days[] = [
                    'date' => $date,
                    'accounts' => (int) ($accounts[$date] ?? 0),
                    'users' => (int) ($users[$date] ?? 0),
                ];
            }

            return $days;
        });
    }

    /**
     * Each account's usage, worded by UsageReport so that this can't disagree
     * with GET /api/superadmin/usage.
     *
     * @return list<array<string, mixed>>
     */
    public function usage(Carbon $from, Carbon $to): array
    {
        return TenantContext::withoutScope(function () use ($from, $to) {
            $totals = $this->usageReport->totals($from, $to);
            $seats = $this->usageReport->seats($to);
            $ai = $this->usageReport->aiTotals($from, $to);

            return HotelGroup::query()
                ->with('hotels:id,hotel_group_id,name')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (HotelGroup $account) => $this->usageRow($this->usageReport->describe(
                    $account,
                    $totals->get($account->id) ?? collect(),
                    $seats->get($account->id) ?? collect(),
                    $ai->get($account->id) ?? collect(),
                    $from,
                )))
                ->all();
        });
    }

    /**
     * @param  array<string, mixed>  $described  UsageReport::describe() for one account
     * @return array<string, mixed>
     */
    private function usageRow(array $described): array
    {
        return [
            'hotel_group_id' => $described['hotel_group_id'],
            'name' => $described['account'],
            'usage' => [
                'seats' => $described['seats'],
                'features' => $described['features'],
                'recommendations' => $described['recommendations'],
            ],
            'ai' => $described['ai'],
        ];
    }

    /**
     * Reservations and bookings created per hotel, of any status.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(Carbon $from, Carbon $to): array
    {
        return TenantContext::withoutScope(function () use ($from, $to) {
            $reservations = $this->createdPerHotel(Reservation::query(), $from, $to);
            $bookings = $this->createdPerHotel(Booking::query(), $from, $to);

            return Hotel::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'hotel_group_id', 'name'])
                ->map(fn (Hotel $hotel) => [
                    'hotel_id' => $hotel->id,
                    'hotel_group_id' => $hotel->hotel_group_id,
                    'name' => $hotel->name,
                    'reservations' => (int) ($reservations[$hotel->id] ?? 0),
                    'bookings' => (int) ($bookings[$hotel->id] ?? 0),
                ])
                ->all();
        });
    }

    /**
     * One statement, so the two figures come from the same moment.
     *
     * @return array{active: int, suspended: int}
     */
    private function userCounts(): array
    {
        $counts = DB::query()
            ->selectSub($this->usersWhoCount()->selectRaw('COUNT(*)'), 'total')
            ->selectSub($this->usersWhoCount()->refusedBySuspension()->selectRaw('COUNT(*)'), 'suspended')
            ->first();

        return [
            'active' => (int) $counts->total - (int) $counts->suspended,
            'suspended' => (int) $counts->suspended,
        ];
    }

    private function usersWhoCount(): Builder
    {
        return User::query()->where('users.role', '!=', UserRole::SUPER_ADMIN->value);
    }

    /**
     * @return Collection<string, int>
     */
    private function createdPerDay(Builder $query, Carbon $from, Carbon $to): Collection
    {
        return $query
            ->whereBetween('created_at', [$from, $to])
            ->groupByRaw('DATE(created_at)')
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->toBase()
            ->pluck('total', 'day');
    }

    /**
     * @return Collection<string, int>
     */
    private function createdPerHotel(Builder $query, Carbon $from, Carbon $to): Collection
    {
        return $query
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('hotel_id')
            ->selectRaw('hotel_id, COUNT(*) AS total')
            ->toBase()
            ->pluck('total', 'hotel_id');
    }
}
