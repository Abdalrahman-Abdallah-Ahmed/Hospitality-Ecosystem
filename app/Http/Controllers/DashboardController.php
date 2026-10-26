<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\VipGuestResource;
use App\Services\Reports\DashboardSummary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * How long a hotel's dashboard is reused before it is recalculated
     * (spec 017 FR-023): its figures are daily operational ones, and a
     * polled dashboard would otherwise re-run its queries on every refresh.
     */
    private const CACHE_SECONDS = 60;

    public function generalData(Request $request, DashboardSummary $summary)
    {
        if (! $request->user()->hasPermission(Permission::DASHBOARD_VIEW)) {
            return apiResponse('This action is unauthorized.', 403);
        }

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $date = $request->date('date');

        // DashboardSummary has no hotel filter of its own: its figures cover
        // every hotel the tenant scope lets the caller see. So the key is that
        // scope, not the user's home hotel, plus the day DashboardSummary
        // treats as today (the server's) and the date asked for. Read only
        // after the checks above, holding plain arrays, never a model. The
        // Admin AI reads DashboardSummary directly, so it stays live.
        $hotelIds = TenantContext::hotelIds();
        $scope = $hotelIds === null ? 'all' : md5(implode(',', collect($hotelIds)->sort()->all()));
        $key = "dashboard:{$scope}:".now()->toDateString().':'.($date?->toDateString() ?? 'today');

        $data = Cache::remember($key, self::CACHE_SECONDS, function () use ($summary, $hotel, $date, $request) {
            // The figures live in DashboardSummary, shared with the Admin AI.
            $figures = $summary->for($hotel, $date);

            return [
                'pending_tasks' => $figures['pending_tasks'],
                'in_progress_tasks' => $figures['in_progress_tasks'],
                'today_arrivals_count' => $figures['today_arrivals']->count(),
                'today_arrivals' => ReservationResource::collection($figures['today_arrivals'])->resolve($request),
                'today_departures_count' => $figures['today_departures']->count(),
                'today_departures' => ReservationResource::collection($figures['today_departures'])->resolve($request),
                'vip_guests_count' => $figures['vip_guests']->count(),
                'vip_guests' => VipGuestResource::collection($figures['vip_guests'])->resolve($request),
                'occupancy' => $figures['occupancy'],
                'booking_value_today' => $figures['booking_value_today'],
                'room_revenue_today' => $figures['room_revenue_today'],
            ];
        });

        return apiResponse('Dashboard data fetched successfully.', 200, $data);
    }
}
