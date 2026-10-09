<?php

namespace App\Services\Reports;

use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The dashboard's figures, for the dashboard endpoint and the Admin AI
 * (SPEC-055 R4). The queries rely on the hotel tenant scope, exactly as the
 * endpoint always has, so callers run inside the hotel's context.
 */
class DashboardSummary
{
    public const MAX_OCCUPANCY_NIGHTS = 31;

    /**
     * `$today` is the date the figures treat as today. The dashboard endpoint
     * leaves it to the server's date, as it always has; the Admin AI passes
     * the hotel's own date, so "today" matches what the admin was told.
     *
     * @return array<string, mixed> the dashboard body, with models in place of resources
     */
    public function for(Hotel $hotel, ?CarbonInterface $date = null, ?string $today = null): array
    {
        $today ??= now()->toDateString();

        $todayArrivals = Reservation::with(['reservationRooms.roomType', 'reservationRooms.room'])
            ->whereDate('arrival_date', $today)
            ->get();

        $todayDepartures = Reservation::with(['reservationRooms.roomType', 'reservationRooms.room'])
            ->whereDate('departure_date', $today)
            ->get();

        // VIPs staff need to look after right now: checked in, or due today.
        $currentVipStays = fn ($query) => $query->where(fn ($query) => $query
            ->where('status', StayStatus::IN_HOUSE)
            ->orWhere(fn ($query) => $query
                ->where('status', StayStatus::EXPECTED)
                ->whereDate('planned_arrival_date', $today)));

        $vipGuests = Guest::where('is_vip', true)
            ->whereHas('stays', $currentVipStays)
            ->with(['stays' => fn ($query) => $currentVipStays($query)->with('room.roomType')])
            ->orderBy('first_name')
            ->get();

        return [
            'pending_tasks' => Task::where('status', TaskStatus::PENDING)->count(),
            'in_progress_tasks' => Task::where('status', TaskStatus::IN_PROGRESS)->count(),
            'today_arrivals' => $todayArrivals,
            'today_departures' => $todayDepartures,
            'vip_guests' => $vipGuests,
            'occupancy' => $this->occupancyOn($hotel, $date, $today),
            'booking_value_today' => Reservation::whereDate('created_at', $today)->sum('reservation_value'),
            'room_revenue_today' => Stay::where('status', StayStatus::IN_HOUSE)->sum('room_revenue'),
        ];
    }

    /**
     * Occupancy for one date: today from the live room statuses, any other
     * date from the stays (rooms.status has no history).
     *
     * @return array{date: string, occupied_rooms: int, total_rooms: int, percentage: float|int, basis: string, as_of: string, historical_supported: bool}
     */
    public function occupancyOn(Hotel $hotel, ?CarbonInterface $date = null, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $requestedDate = $date?->toDateString();
        $isToday = $requestedDate === null || $requestedDate === $today;

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
            $occupiedRooms = Stay::occupiedRoomsOn($hotel, $date);
            $basis = 'stay_events';
        }

        return [
            'date' => $requestedDate ?? $today,
            'occupied_rooms' => $occupiedRooms,
            'total_rooms' => $totalRooms,
            'percentage' => $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0,
            'basis' => $basis,
            'as_of' => now()->toIso8601String(),
            'historical_supported' => true,
        ];
    }

    /**
     * Occupancy night by night, each night computed exactly as the dashboard
     * computes that date.
     *
     * @return list<array{date: string, occupied: int, total: int, percentage: float|int}>
     *
     * @throws DomainRuleException when the range is backwards or longer than MAX_OCCUPANCY_NIGHTS
     */
    public function occupancy(Hotel $hotel, string $from, string $to, ?string $today = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lt($start)) {
            throw new DomainRuleException('The end date must be on or after the start date.', 422);
        }

        if ($start->diffInDays($end) + 1 > self::MAX_OCCUPANCY_NIGHTS) {
            throw new DomainRuleException('Occupancy can be reported for at most '.self::MAX_OCCUPANCY_NIGHTS.' nights at a time.', 422);
        }

        $nights = [];

        for ($night = $start; $night->lte($end); $night = $night->addDay()) {
            $day = $this->occupancyOn($hotel, $night, $today);

            $nights[] = [
                'date' => $day['date'],
                'occupied' => $day['occupied_rooms'],
                'total' => $day['total_rooms'],
                'percentage' => $day['percentage'],
            ];
        }

        return $nights;
    }
}
