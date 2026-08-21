<?php

namespace App\Support\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;

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
     */
    public static function findOrCreateGuest(string $hotelId, array $attributes): Guest
    {
        $guest = Guest::withTrashed()->firstOrCreate(
            [
                'hotel_id' => $hotelId,
                'phone_number' => $attributes['phone_number'],
            ],
            [
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'email' => $attributes['email'] ?? null,
            ]
        );

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

            return $trashed;
        }

        return Reservation::create($attributes);
    }

    /**
     * A confirmed reservation implies its room is now taken, so reflect
     * that on the room itself rather than leaving it "available".
     */
    public static function syncRoomOccupancy(Reservation $reservation): void
    {
        if ($reservation->status === ReservationStatus::CONFIRMED && $reservation->room_id) {
            Room::whereKey($reservation->room_id)->update(['status' => RoomStatusesEnum::OCCUPIED->value]);
        }
    }
}
