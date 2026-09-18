<?php

namespace App\Support\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Services\GuestIdentityService;
use App\Services\StayService;

/**
 * Shared reservation-persistence logic used by both the authenticated
 * ReservationController::store() and CreateReservationTool, so the
 * soft-delete-restore and room-occupancy rules stay in one place.
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
     * A trashed reservation is not visible through normal queries, but its
     * (hotel_id, reservation_id) row still exists, so blindly creating would
     * throw a duplicate-key error. Restore and update it instead.
     *
     * The lookup names the hotel explicitly. Queued callers run with no
     * tenant scope, and matching on the code alone would restore — and
     * overwrite — another hotel's reservation that happens to share it.
     */
    public static function create(array $attributes): Reservation
    {
        $trashed = Reservation::onlyTrashed()
            ->where('hotel_id', $attributes['hotel_id'])
            ->where('reservation_id', $attributes['reservation_id'])
            ->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update($attributes);
            self::syncStay($trashed);

            return $trashed;
        }

        $reservation = Reservation::create($attributes);
        self::syncStay($reservation);

        return $reservation;
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
     * Keeps the status of this reservation's room — and of the room it just
     * moved out of, if the last save changed room_id — in step with who is
     * physically there. Call after syncStay(), which it reads.
     */
    public static function syncRoomOccupancy(Reservation $reservation): void
    {
        $previousRoomId = $reservation->wasChanged('room_id')
            ? ($reservation->getPrevious()['room_id'] ?? null)
            : null;

        foreach (array_unique(array_filter([$reservation->room_id, $previousRoomId])) as $roomId) {
            self::syncRoomStatus($roomId);
        }
    }

    /**
     * A room is occupied while any stay in it is IN_HOUSE, and released back
     * to available once none is. Derived from every stay in the room rather
     * than from one reservation, so booking a room for next week cannot
     * release it while tonight's guest is still in it.
     *
     * Only an occupied room is ever released: a room under maintenance stays
     * under maintenance until a guest actually checks into it.
     */
    private static function syncRoomStatus(string $roomId): void
    {
        $someoneInHouse = Stay::withoutGlobalScope('hotel')
            ->where('room_id', $roomId)
            ->where('status', StayStatus::IN_HOUSE)
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
