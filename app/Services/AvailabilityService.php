<?php

namespace App\Services;

use App\Enums\ActorKind;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Exceptions\InsufficientAvailabilityException;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Audit\EventLogger;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How many rooms of each type a hotel can still sell, night by night, and the
 * guard that stops a reservation from selling more than that. The staff
 * endpoint, both AI tools and ReservationCreator all read the same numbers
 * from here, so they can never disagree.
 *
 * A night is a date a guest sleeps: 12–15 March is the nights of 12, 13 and
 * 14. A line holds one unit of its type on each of its reservation's nights
 * while it is live and its reservation is in ReservationStatus::holdingInventory(),
 * whether or not a physical room is on it. Nothing here is stored.
 *
 * Every query names the hotel: AI tools and the import run without tenant
 * context.
 */
class AvailabilityService
{
    public const MAX_NIGHTS = 90;

    /**
     * The AI tools answer at most this many nights per call, so a question
     * never puts hundreds of cells into the model's context.
     */
    public const AI_MAX_NIGHTS = 31;

    /**
     * The hotel's current date, which decides what "today" and "tonight" mean
     * for lookups and for guests staying past their departure date.
     */
    public function today(Hotel $hotel): string
    {
        return CarbonImmutable::now($hotel->timezone)->toDateString();
    }

    /**
     * Rejects a lookup range that is not two YYYY-MM-DD dates, is empty, is
     * longer than `$maxNights`, or starts before the hotel's today. The AI
     * tools pass the model's raw input straight here.
     */
    public function assertRange(Hotel $hotel, ?string $arrivalDate, ?string $departureDate, int $maxNights = self::MAX_NIGHTS): void
    {
        $arrival = $this->parseDate('arrival_date', $arrivalDate);
        $departure = $this->parseDate('departure_date', $departureDate);

        if ($departure <= $arrival) {
            throw ValidationException::withMessages(['departure_date' => 'The departure date must be after the arrival date.']);
        }

        if ($arrival->diffInDays($departure) > $maxNights) {
            throw ValidationException::withMessages(['departure_date' => "Availability can be checked for at most {$maxNights} nights at a time."]);
        }

        if ($arrival->toDateString() < $this->today($hotel)) {
            throw ValidationException::withMessages(['arrival_date' => 'The arrival date cannot be in the past.']);
        }
    }

    /**
     * The availability grid: for each room type, one cell per night in
     * [arrival, departure) and the lowest sellable number across them.
     * Without `$roomTypeIds` every active type is listed; named types are
     * listed even when inactive. Always three queries, whatever the range.
     *
     * @param  array<int, string>|null  $roomTypeIds
     * @return array{arrival_date: string, departure_date: string, nights: int, room_types: list<array{room_type: RoomType, bookable_for_stay: int, nights: list<array{date: string, total: int, out_of_order: int, booked: int, sellable: int, overbooked: int}>}>}
     */
    public function forHotel(Hotel $hotel, string $arrivalDate, string $departureDate, ?array $roomTypeIds = null): array
    {
        $nights = $this->nightsBetween($arrivalDate, $departureDate);

        $types = RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->when(
                $roomTypeIds !== null,
                fn ($query) => $query->whereIn('id', $roomTypeIds),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('name')
            ->get();

        $typeIds = $types->pluck('id')->all();
        $rooms = $this->roomCounts($hotel->id, $typeIds);
        $booked = $this->countByTypeAndNight(
            $this->holdingNights($hotel, $nights[0], $departureDate)->whereIn('reservation_rooms.room_type_id', $typeIds)
        );

        return [
            'arrival_date' => $nights[0],
            'departure_date' => CarbonImmutable::parse($departureDate)->toDateString(),
            'nights' => count($nights),
            'room_types' => $types
                ->map(fn (RoomType $type) => $this->row($type, $nights, $rooms->get($type->id), $booked[$type->id] ?? []))
                ->values()
                ->all(),
        ];
    }

    /**
     * Locks the given room types of a hotel until the surrounding transaction
     * ends, so two bookings for the same type are checked one after the
     * other and cannot both take the last room. Locked in id order so two
     * multi-type bookings cannot deadlock. Callers pass ids already validated
     * as the hotel's room types.
     *
     * @param  iterable<int, string>  $roomTypeIds
     */
    public function lockTypes(string $hotelId, iterable $roomTypeIds): void
    {
        RoomType::withoutGlobalScope('hotel')->withTrashed()
            ->where('hotel_id', $hotelId)
            ->whereIn('id', collect($roomTypeIds)->unique()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * The units this reservation itself holds, per type and night, as it is
     * saved right now. Empty when it holds nothing (cancelled, checked out,
     * deleted).
     *
     * @return array<string, array<string, int>>
     */
    public function footprint(Reservation $reservation): array
    {
        $hotel = Hotel::findOrFail($reservation->hotel_id);
        $tomorrow = CarbonImmutable::parse($this->today($hotel))->addDay()->toDateString();
        $lastDeparture = max($reservation->departure_date->toDateString(), $tomorrow);

        return $this->countByTypeAndNight(
            $this->holdingNights($hotel, $reservation->arrival_date->toDateString(), $lastDeparture)
                ->where('reservations.id', $reservation->id)
        );
    }

    /**
     * Rejects a change that added units to a type on a night the type has no
     * room left for. Only the cells this change increased are checked, so the
     * reservation's own existing lines never count against it and a night
     * that was already overbooked does not block unrelated edits.
     *
     * Staff may save it anyway with `$override`; the override is audited. An
     * AI actor never can, whatever it asks for.
     *
     * @param  array<string, array<string, int>>  $before  footprint() taken before the change
     *
     * @throws InsufficientAvailabilityException
     */
    public function guard(Reservation $reservation, array $before, bool $override): void
    {
        $increased = $this->increasedCells($before, $this->footprint($reservation));

        if ($increased === []) {
            return;
        }

        $shortfalls = $this->shortfalls(Hotel::findOrFail($reservation->hotel_id), $increased);

        if ($shortfalls === []) {
            return;
        }

        if ($override && EventLogger::currentActorKind() !== ActorKind::AI_AGENT) {
            EventLogger::record($reservation, 'overbooking_overridden', changes: ['shortfalls' => $shortfalls]);

            return;
        }

        throw InsufficientAvailabilityException::for($shortfalls);
    }

    private function parseDate(string $field, ?string $value): CarbonImmutable
    {
        $parsed = $value !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if (! $parsed || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => "The {$field} must be a date in YYYY-MM-DD format."]);
        }

        return CarbonImmutable::instance($parsed);
    }

    /**
     * One type's row of the grid.
     *
     * @param  list<string>  $nights
     * @param  object{total: int, out_of_order: int}|null  $rooms
     * @param  array<string, int>  $booked  units held per night
     * @return array{room_type: RoomType, bookable_for_stay: int, nights: list<array<string, mixed>>}
     */
    private function row(RoomType $type, array $nights, ?object $rooms, array $booked): array
    {
        $total = (int) ($rooms->total ?? 0);
        $outOfOrder = (int) ($rooms->out_of_order ?? 0);
        $usable = $total - $outOfOrder;

        $cells = array_map(fn (string $night) => [
            'date' => $night,
            'total' => $total,
            'out_of_order' => $outOfOrder,
            'booked' => $booked[$night] ?? 0,
            'sellable' => max(0, $usable - ($booked[$night] ?? 0)),
            'overbooked' => max(0, ($booked[$night] ?? 0) - $usable),
        ], $nights);

        return [
            'room_type' => $type,
            'bookable_for_stay' => min(array_column($cells, 'sellable')),
            'nights' => $cells,
        ];
    }

    /**
     * The (type, night) cells where `$after` holds more units than `$before`.
     *
     * @param  array<string, array<string, int>>  $before
     * @param  array<string, array<string, int>>  $after
     * @return array<string, array<string, true>>
     */
    private function increasedCells(array $before, array $after): array
    {
        $increased = [];

        foreach ($after as $typeId => $nights) {
            foreach ($nights as $night => $count) {
                if ($count > ($before[$typeId][$night] ?? 0)) {
                    $increased[$typeId][$night] = true;
                }
            }
        }

        return $increased;
    }

    /**
     * The increased cells that are now overbooked, grouped by type.
     *
     * @param  array<string, array<string, true>>  $increased
     * @return list<array{room_type_id: string, room_type_name: string, nights: list<array{date: string, short: int}>}>
     */
    private function shortfalls(Hotel $hotel, array $increased): array
    {
        $nights = collect($increased)->flatMap(fn (array $cells) => array_keys($cells))->sort()->values();
        $grid = $this->forHotel(
            $hotel,
            $nights->first(),
            CarbonImmutable::parse($nights->last())->addDay()->toDateString(),
            array_keys($increased),
        );

        return collect($grid['room_types'])
            ->map(fn (array $row) => [
                'room_type_id' => $row['room_type']->id,
                'room_type_name' => $row['room_type']->name,
                'nights' => collect($row['nights'])
                    ->filter(fn (array $cell) => $cell['overbooked'] > 0 && isset($increased[$row['room_type']->id][$cell['date']]))
                    ->map(fn (array $cell) => ['date' => $cell['date'], 'short' => $cell['overbooked']])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $shortfall) => $shortfall['nights'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function nightsBetween(string $arrivalDate, string $departureDate): array
    {
        $night = CarbonImmutable::parse($arrivalDate)->startOfDay();
        $departure = CarbonImmutable::parse($departureDate)->startOfDay();
        $nights = [];

        while ($night->lessThan($departure)) {
            $nights[] = $night->toDateString();
            $night = $night->addDay();
        }

        return $nights;
    }

    /**
     * @param  array<int, string>  $typeIds
     * @return Collection<string, object{total: int, out_of_order: int}>
     */
    private function roomCounts(string $hotelId, array $typeIds): Collection
    {
        $statuses = array_map(fn (RoomStatusesEnum $status) => $status->value, RoomStatusesEnum::outOfOrder());
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

        return Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotelId)
            ->whereIn('room_type_id', $typeIds)
            ->groupBy('room_type_id')
            ->selectRaw("room_type_id, count(*) as total, count(*) filter (where status in ({$placeholders})) as out_of_order", $statuses)
            ->toBase()
            ->get()
            ->keyBy('room_type_id');
    }

    /**
     * Every holding line of the hotel on each of its nights within
     * [from, to), one row per line and night, expanded by Postgres so the
     * cost follows the number of lines, not rooms × nights. A checked-in
     * guest whose departure date has passed still holds tonight; one who is
     * due out today does not, so tonight can be sold to the next arrival.
     */
    private function holdingNights(Hotel $hotel, string $from, string $to): Builder
    {
        $today = $this->today($hotel);
        $tomorrow = CarbonImmutable::parse($today)->addDay()->toDateString();
        $holding = array_map(fn (ReservationStatus $status) => $status->value, ReservationStatus::holdingInventory());
        $endExclusive = 'least(case when reservations.status = ? and reservations.departure_date < ?::date then ?::date '
            .'else reservations.departure_date end, ?::date)';

        return DB::table('reservation_rooms')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->crossJoin(DB::raw(
                'lateral generate_series(greatest(reservations.arrival_date, ?::date)::timestamp, '
                ."({$endExclusive} - 1)::timestamp, interval '1 day') as nights(night)"
            ))
            // The lateral join's placeholders come first in the SQL, ahead of
            // the where clauses, so they must lead the bindings.
            ->addBinding([$from, ReservationStatus::CHECKED_IN->value, $today, $tomorrow, $to], 'join')
            ->where('reservation_rooms.hotel_id', $hotel->id)
            ->where('reservations.hotel_id', $hotel->id)
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->whereNull('reservation_rooms.deleted_at')
            ->whereNull('reservations.deleted_at')
            ->whereIn('reservations.status', $holding)
            ->where('reservations.arrival_date', '<', $to);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function countByTypeAndNight(Builder $holdingNights): array
    {
        $rows = $holdingNights
            ->groupBy('reservation_rooms.room_type_id', DB::raw('nights.night'))
            ->selectRaw('reservation_rooms.room_type_id, nights.night::date as night, count(*) as booked')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->room_type_id][CarbonImmutable::parse($row->night)->toDateString()] = (int) $row->booked;
        }

        return $counts;
    }
}
