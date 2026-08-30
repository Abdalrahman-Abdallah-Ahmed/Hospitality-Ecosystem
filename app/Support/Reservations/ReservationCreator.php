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
     * A trashed reservation is not visible through normal queries, but its
     * unique reservation_id row still exists, so blindly creating would
     * throw a duplicate-key error. Restore and update it instead.
     */
    public static function create(array $attributes): Reservation
    {
        $trashed = Reservation::onlyTrashed()->where('reservation_id', $attributes['reservation_id'])->first();

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
     * on every create/update) and keeps its status in step with the
     * reservation's own status. The one place both WP-2 acceptance
     * criteria ("every reservation automatically produces one stay") and
     * the expected/check-in/check-out/cancel transitions are driven from.
     * No-show is deliberately not one of them — see the match below.
     */
    public static function syncStay(Reservation $reservation): void
    {
        $stayService = app(StayService::class);
        $stay = $stayService->createFromReservation($reservation);

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
     * The room's status reflects whether its stay is actually IN_HOUSE —
     * a real, physical fact — not whether the reservation is merely
     * confirmed. This also closes a known gap: previously, nothing ever
     * freed a room back to "available" on cancel/checkout/no-show; now
     * DEPARTED/NO_SHOW/CANCELLED do so automatically. A stay still
     * EXPECTED (booked, not yet arrived) intentionally leaves the room's
     * current status untouched — a future booking shouldn't block the
     * room from showing available in the meantime.
     */
    public static function syncRoomOccupancy(Reservation $reservation): void
    {
        if (! $reservation->room_id) {
            return;
        }

        $stay = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->first();

        $roomStatus = match ($stay?->status) {
            StayStatus::IN_HOUSE => RoomStatusesEnum::OCCUPIED,
            StayStatus::EXPECTED => RoomStatusesEnum::AVAILABLE,
            StayStatus::DEPARTED, StayStatus::NO_SHOW, StayStatus::CANCELLED => RoomStatusesEnum::AVAILABLE,
            default => null,
        };

        if ($roomStatus) {
            Room::whereKey($reservation->room_id)->update(['status' => $roomStatus->value]);
        }
    }
}
