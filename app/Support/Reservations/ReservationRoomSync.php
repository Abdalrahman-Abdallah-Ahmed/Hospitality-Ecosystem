<?php

namespace App\Support\Reservations;

use App\Enums\ActorKind;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The rules for a reservation's room lines, shared by every way a
 * reservation is written (staff API, Admin AI tool, import), so none of them
 * can skip one. Pure checks and planning: it reads, validates and says what
 * to change, and ReservationCreator does the writing inside its transaction.
 *
 * Every lookup names the hotel explicitly instead of relying on the tenant
 * scope, because queued and AI callers run with no tenant context.
 * Failures are ValidationExceptions keyed `rooms` or `rooms.{i}.{field}`,
 * where {i} is the item's index in the request.
 */
class ReservationRoomSync
{
    public const MAX_UNITS = 50;

    /**
     * Turns request items (`room_type_id`, `quantity`, `room_id`) into one unit
     * per room. Each unit keeps the index of the item it came from, for error
     * keys.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{index: int, room_type_id: mixed, room_id: mixed}>
     */
    public static function expand(array $items): array
    {
        $units = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = $item['quantity'] ?? 1;

            if (! is_int($quantity) && ! ctype_digit((string) $quantity) || (int) $quantity < 1 || (int) $quantity > self::MAX_UNITS) {
                self::fail("rooms.{$index}.quantity", 'The quantity must be between 1 and '.self::MAX_UNITS.'.');
            }

            $quantity = (int) $quantity;
            $roomId = $item['room_id'] ?? null;

            if ($roomId !== null && $quantity > 1) {
                self::fail("rooms.{$index}.room_id", 'A physical room can only be set on a line with a quantity of 1.');
            }

            for ($i = 0; $i < $quantity; $i++) {
                $units[] = ['index' => $index, 'room_type_id' => $item['room_type_id'] ?? null, 'room_id' => $roomId];
            }
        }

        return $units;
    }

    /**
     * New lines may only use an active, live room type of the hotel, and a
     * room of that hotel and that type.
     *
     * @param  array<int, array{index: int, room_type_id: mixed, room_id: mixed}>  $units
     */
    public static function validateNewUnits(string $hotelId, array $units): void
    {
        if ($units === []) {
            self::fail('rooms', 'A reservation needs at least one room.');
        }

        self::assertUnitCount(count($units));

        $typeIds = collect($units)->pluck('room_type_id')->filter(fn ($id) => self::isUuid($id))->unique();
        $types = RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotelId)
            ->where('is_active', true)
            ->whereIn('id', $typeIds)
            ->pluck('id')
            ->all();

        foreach ($units as $unit) {
            if (! in_array($unit['room_type_id'], $types, true)) {
                self::fail("rooms.{$unit['index']}.room_type_id", 'The selected room type is not available.');
            }

            if ($unit['room_id'] !== null) {
                self::assertRoomFits($hotelId, $unit['room_id'], $unit['room_type_id'], "rooms.{$unit['index']}.room_id");
            }
        }

        self::assertNoDuplicateRooms(collect($units)->mapWithKeys(fn ($unit) => [$unit['index'] => $unit['room_id']])->all());
    }

    /**
     * Plans an update of the lines from the desired list of live lines.
     * Items with `id` keep that line (optionally moving its room); items
     * without are new lines; live lines left out are cancelled. What is
     * allowed depends on the reservation's status before this update.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{moves: array<string, string|null>, create: array<int, array{index: int, room_type_id: mixed, room_id: mixed}>, cancel: Collection<int, ReservationRoom>}
     */
    public static function diff(Reservation $reservation, array $items, ReservationStatus $statusBefore): array
    {
        if (in_array($statusBefore, [ReservationStatus::CHECKED_OUT, ReservationStatus::CANCELLED], true)) {
            self::fail('rooms', "Rooms cannot be changed on a {$statusBefore->value} reservation.");
        }

        $checkedIn = $statusBefore === ReservationStatus::CHECKED_IN;
        $live = $reservation->reservationRooms()->active()->get()->keyBy('id');

        $moves = [];
        $kept = [];
        $newItems = [];

        foreach (array_values($items) as $index => $item) {
            $lineId = $item['id'] ?? null;

            if ($lineId === null) {
                if ($checkedIn) {
                    self::fail('rooms', 'Rooms cannot be added to a checked-in reservation; only room moves are allowed.');
                }

                $newItems[$index] = $item;

                continue;
            }

            $line = $live->get($lineId);

            if (! $line || isset($kept[$lineId])) {
                self::fail("rooms.{$index}.id", 'The selected room line is not a live line of this reservation.');
            }

            $kept[$lineId] = $index;

            if (isset($item['room_type_id']) && $item['room_type_id'] !== $line->room_type_id) {
                self::fail("rooms.{$index}.room_type_id", "A line's room type cannot be changed; cancel the line and add a new one.");
            }

            if (! array_key_exists('room_id', $item) || $item['room_id'] === $line->room_id) {
                continue;
            }

            if ($item['room_id'] === null) {
                if ($checkedIn) {
                    self::fail("rooms.{$index}.room_id", "A checked-in guest's room can be changed but not cleared.");
                }
            } else {
                self::assertRoomFits($reservation->hotel_id, $item['room_id'], $line->room_type_id, "rooms.{$index}.room_id");
            }

            $moves[$lineId] = $item['room_id'];
        }

        $cancel = $live->reject(fn (ReservationRoom $line) => isset($kept[$line->id]))->values();

        if ($checkedIn && $cancel->isNotEmpty()) {
            self::fail('rooms', 'Rooms cannot be removed from a checked-in reservation; only room moves are allowed.');
        }

        // Expand the new items with their original request indexes.
        $create = [];
        foreach ($newItems as $index => $item) {
            foreach (self::expand([$item]) as $unit) {
                $create[] = [...$unit, 'index' => $index];
            }
        }

        $resultingCount = count($kept) + count($create);

        if ($resultingCount === 0) {
            self::fail('rooms', 'A reservation needs at least one room; cancel the reservation instead.');
        }

        self::assertUnitCount($resultingCount);

        if ($create !== []) {
            self::validateNewUnits($reservation->hotel_id, $create);
        }

        // Every room the lines will hold afterwards, keyed by request index.
        $finalRooms = [];
        foreach ($kept as $lineId => $index) {
            $finalRooms[$index] = array_key_exists($lineId, $moves) ? $moves[$lineId] : $live[$lineId]->room_id;
        }
        foreach ($create as $unit) {
            $finalRooms[] = $unit['room_id'];
        }
        self::assertNoDuplicateRooms($finalRooms);

        return ['moves' => $moves, 'create' => $create, 'cancel' => $cancel];
    }

    /**
     * The party must fit the rooms as a whole: everyone within the combined
     * maximum occupancy, adults within the combined adult capacity. Staff may
     * override on purpose; an AI actor never can. Returns whether an override
     * was used, so the caller can audit it.
     *
     * @param  Collection<int, RoomType>  $lineTypes  one entry per live line
     */
    public static function assertCapacity(int $adults, int $children, Collection $lineTypes, bool $override): bool
    {
        $maxOccupancy = $lineTypes->sum('max_occupancy');
        $adultCapacity = $lineTypes->sum('adult_capacity');

        if ($adults + $children <= $maxOccupancy && $adults <= $adultCapacity) {
            return false;
        }

        if ($override && EventLogger::currentActorKind() !== ActorKind::AI_AGENT) {
            return true;
        }

        self::fail('rooms', "The party ({$adults} adults, {$children} children) is larger than the booked rooms hold "
            ."({$maxOccupancy} guests, {$adultCapacity} adults). Add rooms, or send capacity_override to save it anyway.");
    }

    public static function assertUnitCount(int $count): void
    {
        if ($count > self::MAX_UNITS) {
            self::fail('rooms', 'A reservation can hold at most '.self::MAX_UNITS.' rooms.');
        }
    }

    private static function assertRoomFits(string $hotelId, mixed $roomId, mixed $roomTypeId, string $key): void
    {
        $room = self::isUuid($roomId)
            ? Room::withoutGlobalScope('hotel')->where('hotel_id', $hotelId)->find($roomId)
            : null;

        if (! $room) {
            self::fail($key, 'The selected room is not available.');
        }

        if ($room->room_type_id !== $roomTypeId) {
            self::fail($key, "Room {$room->room_number} is not of the line's room type.");
        }
    }

    /**
     * @param  array<int, mixed>  $roomIdsByIndex
     */
    private static function assertNoDuplicateRooms(array $roomIdsByIndex): void
    {
        $seen = [];

        foreach ($roomIdsByIndex as $index => $roomId) {
            if ($roomId === null) {
                continue;
            }

            if (isset($seen[$roomId])) {
                self::fail("rooms.{$index}.room_id", 'The same room cannot be on two lines of one reservation.');
            }

            $seen[$roomId] = true;
        }
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
