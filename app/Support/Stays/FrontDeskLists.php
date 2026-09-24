<?php

namespace App\Support\Stays;

use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The front desk's daily lists (FR-016), shared by the stays endpoints and
 * the Admin AI's stays tool so both answer the same:
 *
 * - arrivals: stays still expected whose arrival is the day or earlier (late
 *   ones included), on live lines of pending, confirmed or checked-in
 *   reservations. One whose departure has also passed is listed too, flagged,
 *   so the desk corrects or cancels it.
 * - departures: stays in the house whose departure is the day or earlier.
 * - in-house: every stay in the house.
 *
 * Each list is one query plus a fixed set of eager loads, sorted by room
 * number (unassigned last), then guest name.
 */
class FrontDeskLists
{
    public const LISTS = ['arrivals', 'departures', 'in_house'];

    public static function today(Hotel $hotel): string
    {
        return CarbonImmutable::now($hotel->timezone)->toDateString();
    }

    /**
     * @return Collection<int, Stay>
     */
    public static function get(Hotel $hotel, string $list, string $day): Collection
    {
        $query = self::base($hotel);

        match ($list) {
            'arrivals' => $query
                ->where('stays.status', StayStatus::EXPECTED)
                ->whereDate('stays.planned_arrival_date', '<=', $day)
                ->whereHas('reservation', fn (Builder $q) => $q->withoutGlobalScope('hotel')
                    ->whereIn('status', [ReservationStatus::PENDING, ReservationStatus::CONFIRMED, ReservationStatus::CHECKED_IN]))
                ->whereHas('reservationRoom', fn (Builder $q) => $q->withoutGlobalScope('hotel')
                    ->where('status', '!=', ReservationRoomStatus::CANCELLED->value)),
            'departures' => $query
                ->where('stays.status', StayStatus::IN_HOUSE)
                ->whereDate('stays.planned_departure_date', '<=', $day),
            'in_house' => $query->where('stays.status', StayStatus::IN_HOUSE),
        };

        return $query->get()
            ->sortBy(fn (Stay $stay) => [
                $stay->room === null ? 1 : 0,
                $stay->room?->room_number ?? '',
                mb_strtolower(trim(($stay->guest?->first_name ?? '').' '.($stay->guest?->last_name ?? ''))),
            ])
            ->values();
    }

    private static function base(Hotel $hotel): Builder
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('stays.hotel_id', $hotel->id)
            ->with(['guest', 'reservation', 'reservationRoom.roomType', 'room']);
    }
}
