<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\VipGuestResource;
use App\Services\Reports\DashboardSummary;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function generalData(Request $request, DashboardSummary $summary)
    {
        if (! $request->user()->hasPermission(Permission::DASHBOARD_VIEW)) {
            return apiResponse('This action is unauthorized.', 403);
        }

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        // The figures live in DashboardSummary, shared with the Admin AI.
        $figures = $summary->for($hotel, $request->date('date'));

        $data = [
            'pending_tasks' => $figures['pending_tasks'],
            'in_progress_tasks' => $figures['in_progress_tasks'],
            'today_arrivals_count' => $figures['today_arrivals']->count(),
            'today_arrivals' => ReservationResource::collection($figures['today_arrivals']),
            'today_departures_count' => $figures['today_departures']->count(),
            'today_departures' => ReservationResource::collection($figures['today_departures']),
            'vip_guests_count' => $figures['vip_guests']->count(),
            'vip_guests' => VipGuestResource::collection($figures['vip_guests']),
            'occupancy' => $figures['occupancy'],
            'booking_value_today' => $figures['booking_value_today'],
            'room_revenue_today' => $figures['room_revenue_today'],
        ];

        return apiResponse('Dashboard data fetched successfully.', 200, $data);
    }
}
