<?php

namespace App\Support\Reservations;

use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\GuestIdentityService;
use App\Services\StayService;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one domain operation that writes reservations and their room lines,
 * used by ReservationController, CreateReservationTool and
 * ReservationsImport, so the line rules (ReservationRoomSync), the
 * soft-delete-restore and the room-occupancy rules stay in one place.
 */
class ReservationCreator
{
    /**
     * Guests are matched by phone number within a hotel; a trashed match is
     * restored rather than duplicated, since phone_number is how repeat
     * guests are recognized across channels (WhatsApp, imports, etc).
     *
     * Matching goes through GuestIdentityService's normalised phone hash,
     * not a raw string comparison — the callers of this method (WhatsApp,
     * CSV imports) are exactly where the same real number shows up
     * formatted differently each time (e.g. "+20 115 179 3758" from a
     * spreadsheet vs. "201151793758" from a WhatsApp webhook), so an exact
     * match would silently create a duplicate guest for the same person.
     * Email is deliberately never part of the match here (only used to
     * fill in a new guest's defaults) — this method's whole contract is
     * "recognize by phone," not "by whichever signal wins."
     */
    public static function findOrCreateGuest(string $hotelId, array $attributes): Guest
    {
        $guest = app(GuestIdentityService::class)->findExistingGuest($hotelId, null, $attributes['phone_number'] ?? null);

        if (! $guest) {
            $guest = Guest::create([
                'hotel_id' => $hotelId,
                'phone_number' => $attributes['phone_number'],
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'email' => $attributes['email'] ?? null,
            ]);
        }

        if ($guest->trashed()) {
            $guest->restore();
        }

        return $guest;
    }

    /**
     * Whether a live reservation at this hotel already uses this code.
     * Codes are unique per hotel — two properties may both issue "RES-1001" —
     * and that composite index is beyond what the schema-derived validation
     * rules can check. A soft-deleted match is not counted: create() restores
     * it instead.
     */
    public static function isReservationIdInUse(string $hotelId, string $reservationId): bool
    {
        return Reservation::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotelId)
            ->where('reservation_id', $reservationId)
            ->exists();
    }

    /**
     * Creates a reservation with its room lines — one per booked unit, see
     * ReservationRoomSync::expand() for the `$rooms` shape — in one
     * transaction, so a failure never leaves a reservation without its rooms.
     *
     * A trashed reservation is not visible through normal queries, but its
     * (hotel_id, reservation_id) row still exists, so blindly creating would
     * throw a duplicate-key error. Restore and update it instead; its old
     * lines are cancelled and replaced by the new ones.
     *
     * The lookup names the hotel explicitly. Queued callers run with no
     * tenant scope, and matching on the code alone would restore — and
     * overwrite — another hotel's reservation that happens to share it.
     *
     * `$fromImport` is for the reservation import only: legacy data is
     * recorded as it is rather than rejected, so the party-capacity check and
     * the availability guard are skipped and a room type the hotel has since
     * deactivated is accepted.
     *
     * The booked room types are locked first (AvailabilityService::lockTypes()),
     * so two bookings for the last room of a type are checked one after the
     * other. `$overbookOverride` saves a booking that oversells anyway; the
     * caller has already checked the actor may.
     *
     * @param  array<int, array<string, mixed>>  $rooms
     */
    public static function create(array $attributes, array $rooms, bool $capacityOverride = false, bool $fromImport = false, bool $overbookOverride = false): Reservation
    {
        unset($attributes['room_id']);

        $units = ReservationRoomSync::expand($rooms);
        ReservationRoomSync::validateNewUnits($attributes['hotel_id'], $units, allowInactive: $fromImport);

        return DB::transaction(function () use ($attributes, $units, $capacityOverride, $fromImport, $overbookOverride) {
            $availability = app(AvailabilityService::class);
            $availability->lockTypes($attributes['hotel_id'], array_column($units, 'room_type_id'));

            $reservation = Reservation::onlyTrashed()
                ->where('hotel_id', $attributes['hotel_id'])
                ->where('reservation_id', $attributes['reservation_id'])
                ->first();

            $previousRoomIds = [];

            if ($reservation) {
                $previousRoomIds = self::roomIdsOf($reservation);
                $reservation->restore();
                $reservation->update($attributes);
                self::cancelLines($reservation->reservationRooms()->active()->get());
            } else {
                $reservation = Reservation::create($attributes);
            }

            foreach ($units as $unit) {
                self::addLine($reservation, $unit);
            }

            if (! $fromImport) {
                self::checkCapacity($reservation, $capacityOverride);

                // A restored reservation's old lines were just cancelled, so
                // everything it holds now is new: nothing to subtract.
                $availability->guard($reservation, [], $overbookOverride);
            }

            self::syncStay($reservation);
            self::syncRoomOccupancy([...$previousRoomIds, ...self::roomIdsOf($reservation)]);

            return $reservation;
        });
    }

    /**
     * Updates a reservation and, when `$rooms` is given, its lines, in one
     * transaction. `$rooms` is the desired list of live lines (see
     * ReservationRoomSync::diff()); null leaves the lines alone.
     *
     * Cancelling the reservation cancels every live line and marks them as
     * cancelled with it. Bringing a cancelled reservation back (any other
     * status) restores those lines, unless `$rooms` says which lines it
     * should have instead.
     *
     * Only what the change adds (more lines, new or longer dates) is checked
     * against availability: the reservation's footprint from before the
     * change is subtracted, so its own lines never count against it.
     *
     * @param  array<int, array<string, mixed>>|null  $rooms
     */
    public static function update(Reservation $reservation, array $attributes, ?array $rooms, bool $capacityOverride = false, bool $overbookOverride = false): Reservation
    {
        unset($attributes['room_id']);

        $statusBefore = $reservation->status;
        $statusAfter = isset($attributes['status'])
            ? ($attributes['status'] instanceof ReservationStatus ? $attributes['status'] : ReservationStatus::from($attributes['status']))
            : $statusBefore;
        $reactivating = $statusBefore === ReservationStatus::CANCELLED && $statusAfter !== ReservationStatus::CANCELLED;

        $plan = $rooms !== null
            ? ReservationRoomSync::diff($reservation, $rooms, $statusBefore, $reactivating)
            : null;

        return DB::transaction(function () use ($reservation, $attributes, $plan, $capacityOverride, $reactivating, $overbookOverride) {
            // Every line's type, cancelled ones too: reactivating the
            // reservation brings its cancelled lines back into inventory.
            $availability = app(AvailabilityService::class);
            $availability->lockTypes($reservation->hotel_id, [
                ...$reservation->reservationRooms()->pluck('room_type_id')->all(),
                ...array_column($plan['create'] ?? [], 'room_type_id'),
            ]);
            $footprintBefore = $availability->footprint($reservation);

            $roomIdsBefore = self::roomIdsOf($reservation);

            $reservation->update($attributes);
            $partyChanged = $reservation->wasChanged(['adults', 'children']);

            if ($plan) {
                self::applyPlan($reservation, $plan);
            } elseif ($reactivating) {
                self::reinstateLines($reservation->reservationRooms()->where('cancelled_with_reservation', true)->get());
            }

            if ($reservation->status === ReservationStatus::CANCELLED) {
                self::cancelLines($reservation->reservationRooms()->active()->get(), withReservation: true);
            }

            if (($plan || $partyChanged || $reactivating) && $reservation->status !== ReservationStatus::CANCELLED) {
                self::checkCapacity($reservation, $capacityOverride);
            }

            $availability->guard($reservation, $footprintBefore, $overbookOverride);

            self::syncStay($reservation);
            self::syncRoomOccupancy([...$roomIdsBefore, ...self::roomIdsOf($reservation)]);

            return $reservation;
        });
    }

    /**
     * Every room ever put on this reservation's lines, cancelled ones included,
     * so an occupancy sync after a change also reaches the rooms it released.
     *
     * @return array<int, string>
     */
    public static function roomIdsOf(Reservation $reservation): array
    {
        return ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->whereNotNull('room_id')
            ->pluck('room_id')
            ->all();
    }

    /**
     * @param  array{room_type_id: mixed, room_id: mixed}  $unit
     */
    private static function addLine(Reservation $reservation, array $unit): ReservationRoom
    {
        return ReservationRoom::create([
            'hotel_id' => $reservation->hotel_id,
            'reservation_id' => $reservation->id,
            'room_type_id' => $unit['room_type_id'],
            'room_id' => $unit['room_id'],
            'status' => ReservationRoomStatus::RESERVED,
        ]);
    }

    /**
     * Writes a planned line update in an order the database accepts. One
     * room may sit on only one live line of a reservation, and the unique
     * index is checked after every row, so: removed lines give their rooms
     * up first, every moving line then lets go of its old room, and only
     * then do moves, reinstated lines and new lines take theirs. Swapping
     * two lines' rooms, or moving into a room a removed line held, works.
     *
     * @param  array{moves: array<string, string|null>, create: array<int, array<string, mixed>>, cancel: iterable<ReservationRoom>, reinstate: array<int, string>}  $plan
     */
    private static function applyPlan(Reservation $reservation, array $plan): void
    {
        self::cancelLines($plan['cancel']);

        $lines = ReservationRoom::withoutGlobalScope('hotel')
            ->whereIn('id', [...array_keys($plan['moves']), ...$plan['reinstate']])
            ->get()
            ->keyBy('id');

        // Let go of old rooms quietly; the audited change is the final one.
        foreach (array_keys($plan['moves']) as $lineId) {
            if ($lines[$lineId]->room_id !== null) {
                ReservationRoom::withoutGlobalScope('hotel')->whereKey($lineId)->update(['room_id' => null]);
            }
        }

        self::reinstateLines($lines->only($plan['reinstate']));

        foreach ($plan['moves'] as $lineId => $roomId) {
            $lines[$lineId]->update(['room_id' => $roomId]);
        }

        foreach ($plan['create'] as $unit) {
            self::addLine($reservation, $unit);
        }
    }

    /**
     * Cancelled through the model, one by one, so each is audited.
     * `$withReservation` marks lines cancelled because the whole
     * reservation was, so bringing it back can restore them.
     *
     * @param  iterable<ReservationRoom>  $lines
     */
    private static function cancelLines(iterable $lines, bool $withReservation = false): void
    {
        foreach ($lines as $line) {
            $line->update([
                'status' => ReservationRoomStatus::CANCELLED,
                'cancelled_with_reservation' => $withReservation,
            ]);
        }
    }

    /**
     * @param  iterable<ReservationRoom>  $lines
     */
    private static function reinstateLines(iterable $lines): void
    {
        foreach ($lines as $line) {
            $line->update([
                'status' => ReservationRoomStatus::RESERVED,
                'cancelled_with_reservation' => false,
            ]);
        }
    }

    /**
     * Skipped for a reservation with no live lines: only legacy rows written
     * outside this class can be in that state, and there is nothing to
     * measure the party against.
     */
    private static function checkCapacity(Reservation $reservation, bool $override): void
    {
        $typeIds = ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->active()
            ->pluck('room_type_id');

        if ($typeIds->isEmpty()) {
            return;
        }

        $types = RoomType::withoutGlobalScope('hotel')->withTrashed()
            ->whereIn('id', $typeIds->unique())
            ->get()
            ->keyBy('id');

        $lineTypes = $typeIds->map(fn (string $id) => $types[$id]);
        $adults = (int) ($reservation->adults ?? 1);
        $children = (int) ($reservation->children ?? 0);

        if (ReservationRoomSync::assertCapacity($adults, $children, $lineTypes, $override)) {
            EventLogger::record($reservation, 'capacity_overridden', changes: [
                'adults' => $adults,
                'children' => $children,
                'max_occupancy' => $lineTypes->sum('max_occupancy'),
                'adult_capacity' => $lineTypes->sum('adult_capacity'),
            ]);
        }
    }

    /**
     * Ensures a stay exists for this reservation (idempotent — safe to call
     * on every create/update) and keeps it in step with the reservation: its
     * planned side via StayService, and its status via the match below. The
     * one place both WP-2 acceptance criteria ("every reservation
     * automatically produces one stay") and the expected/check-in/check-out/
     * cancel transitions are driven from. No-show is deliberately not one of
     * them — see the match below.
     */
    public static function syncStay(Reservation $reservation): void
    {
        $stayService = app(StayService::class);
        $stay = $stayService->syncFromReservation($reservation);

        match ($reservation->status) {
            ReservationStatus::CHECKED_IN => $stayService->checkIn($stay),
            ReservationStatus::CHECKED_OUT => $stayService->checkOut($stay),
            ReservationStatus::CANCELLED => $stayService->markCancelled($stay),
            // PENDING and CONFIRMED are both "booked, not arrived yet" as far
            // as the stay is concerned — neither implies a no-show, which
            // specifically means the planned arrival date already passed
            // with nobody checking in. Nothing currently detects that (see
            // StayService::markNoShow()'s docblock); it is not a reservation
            // status transition, so it doesn't belong in this match.
            ReservationStatus::CONFIRMED, ReservationStatus::PENDING => $stayService->markExpected($stay),
            default => null,
        };
    }

    /**
     * Keeps the status of each given room in step with who is physically
     * there. Pass every room an operation touched — the rooms its lines hold
     * now and the ones it just released or moved out of. Call after
     * syncStay(), which it reads.
     *
     * @param  array<int, string|null>  $roomIds
     */
    public static function syncRoomOccupancy(array $roomIds): void
    {
        foreach (array_unique(array_filter($roomIds)) as $roomId) {
            self::syncRoomStatus($roomId);
        }
    }

    /**
     * A room is occupied while any in-house guest is in it, and released back
     * to available once none is. Derived from everyone in the room rather
     * than from one reservation, so booking a room for next week cannot
     * release it while tonight's guest is still in it.
     *
     * Only an occupied room is ever released: a room under maintenance stays
     * under maintenance until a guest actually checks into it.
     */
    private static function syncRoomStatus(string $roomId): void
    {
        $someoneInHouse = DB::query()
            ->fromSub(ReservationRoom::inHouseRoomIds(), 'in_house')
            ->where('room_id', $roomId)
            ->exists();

        $room = Room::withoutGlobalScope('hotel')->whereKey($roomId);

        if ($someoneInHouse) {
            $room->update(['status' => RoomStatusesEnum::OCCUPIED->value]);

            return;
        }

        $room->where('status', RoomStatusesEnum::OCCUPIED->value)
            ->update(['status' => RoomStatusesEnum::AVAILABLE->value]);
    }
}
