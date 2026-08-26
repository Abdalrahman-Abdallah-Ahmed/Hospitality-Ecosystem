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

        $pendingTasks = Task::where('status', TaskStatus::PENDING)->count();
        $inProgressTasks = Task::where('status', TaskStatus::IN_PROGRESS)->count();

        $todayArrivals = Reservation::with('room')
            ->whereDate('arrival_date', now()->toDateString())
            ->get();

        $todayDepartures = Reservation::with('room')
            ->whereDate('departure_date', now()->toDateString())
            ->get();

        $requestedDate = $request->date('date')?->toDateString();
        $isToday = $requestedDate === null || $requestedDate === now()->toDateString();

        $totalRooms = Room::count();

        if ($isToday) {
            $occupiedRooms = Room::where('status', RoomStatusesEnum::OCCUPIED)->count();
            $occupancyPercentage = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0;
        } else {
            // rooms.status is a single current value, not a history — occupancy for any
            // other date can't be computed correctly until WP-2's stay events land.
            $occupiedRooms = null;
            $occupancyPercentage = null;
        }

        $bookingValueToday = Reservation::whereDate('created_at', now()->toDateString())
            ->sum('reservation_value');

        $data = [
            'pending_tasks' => $pendingTasks,
            'in_progress_tasks' => $inProgressTasks,
            'today_arrivals_count' => $todayArrivals->count(),
            'today_arrivals' => $todayArrivals,
            'today_departures_count' => $todayDepartures->count(),
            'today_departures' => $todayDepartures,
            'occupancy' => [
                'date' => $requestedDate ?? now()->toDateString(),
                'occupied_rooms' => $occupiedRooms,
                'total_rooms' => $totalRooms,
                'percentage' => $occupancyPercentage,
                'basis' => 'room_status_snapshot',
                'as_of' => now()->toIso8601String(),
                'historical_supported' => false,   // becomes true in WP-2
            ],
            'booking_value_today' => $bookingValueToday,
            'room_revenue_today' => null,    // TODO(WP-2): revenue for stays IN HOUSE today
        ];

        return apiResponse('Dashboard data fetched successfully.', 200, $data);
    }
}
