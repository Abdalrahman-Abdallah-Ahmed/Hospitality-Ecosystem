<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatusesEnum;
use App\Enums\TaskStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Task;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function generalData(Request $request)
    {
        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $pendingTasks = Task::where('hotel_id', $hotel->id)->where('status', TaskStatus::PENDING)->count();
        $inProgressTasks = Task::where('hotel_id', $hotel->id)->where('status', TaskStatus::IN_PROGRESS)->count();

        $todayArrivals = Reservation::with('room')
            ->where('hotel_id', $hotel->id)
            ->whereDate('arrival_date', now()->toDateString())
            ->get();

        $todayDepartures = Reservation::with('room')
            ->where('hotel_id', $hotel->id)
            ->whereDate('departure_date', now()->toDateString())
            ->get();

        $totalRooms = Room::where('hotel_id', $hotel->id)->count();
        $occupiedRooms = Room::where('hotel_id', $hotel->id)->where('status', RoomStatusesEnum::OCCUPIED)->count();
        $occupancyPercentage = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0;

        $revenueToday = Reservation::where('hotel_id', $hotel->id)
            ->whereDate('created_at', now()->toDateString())
            ->sum('reservation_value');

        return apiResponse('Dashboard data fetched successfully.', 200, [
            'pending_tasks' => $pendingTasks,
            'in_progress_tasks' => $inProgressTasks,
            'today_arrivals_count' => $todayArrivals->count(),
            'today_arrivals' => $todayArrivals,
            'today_departures_count' => $todayDepartures->count(),
            'today_departures' => $todayDepartures,
            'occupancy_percentage' => $occupancyPercentage,
            'revenue_today' => $revenueToday,
        ]);
    }
}
