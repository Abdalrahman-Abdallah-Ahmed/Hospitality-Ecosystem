<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
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

        $requestedCarbonDate = $request->date('date');
        $requestedDate = $requestedCarbonDate?->toDateString();
        $isToday = $requestedDate === null || $requestedDate === now()->toDateString();

        $totalRooms = Room::count();

        if ($isToday) {
            // The live, real-time room-status snapshot — unaffected by whether
            // a stay record exists, so it stays correct even for ad-hoc room
            // states (e.g. maintenance) that never went through a reservation.
            $occupiedRooms = Room::where('status', RoomStatusesEnum::OCCUPIED)->count();
            $basis = 'room_status_snapshot';
        } else {
            // rooms.status has no history, but stays do — this is the only way
            // to answer "what was/will be occupancy on some other date" at all.
            $occupiedRooms = Stay::occupiedRoomsOn($hotel, $requestedCarbonDate);
            $basis = 'stay_events';
        }

        $occupancyPercentage = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0;

        $bookingValueToday = Reservation::whereDate('created_at', now()->toDateString())
            ->sum('reservation_value');

        $roomRevenueToday = Stay::where('status', StayStatus::IN_HOUSE)->sum('room_revenue');

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
                'basis' => $basis,
                'as_of' => now()->toIso8601String(),
                'historical_supported' => true,
            ],
            'booking_value_today' => $bookingValueToday,
            'room_revenue_today' => $roomRevenueToday,
        ];

        return apiResponse('Dashboard data fetched successfully.', 200, $data);
    }
}
