<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Requests\WhatsAppReservationStoreRequest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\WhatsAppDevice;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $reservations = GenericQuery::apply(
            Reservation::with(['hotel', 'guest', 'room'])
                ->where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Reservations fetched successfully.', 200, $reservations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Reservation::class);

        $validated = $request->validated();

        if ($error = $this->guardHotelScopedReferences($validated, $validated['hotel_id'])) {
            return $error;
        }

        $reservation = $this->createOrRestoreReservation($validated);

        $this->syncRoomOccupancy($reservation);

        return apiResponse('Reservation created successfully.', 201, $reservation->load(['hotel', 'guest', 'room']));
    }

    /**
     * Store a newly sent resource from whatsapp in storage.
     */
    public function storeFromWhatsApp(WhatsAppReservationStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $room = null;

        $device = WhatsAppDevice::where('phone_number', $validated['phone_number'])->first();

        if (! $device || $device->status !== 'active') {
            return apiResponse('WhatsApp device not paired.', 403);
        }

        $hotel = $device->hotel;

        if (! empty($validated['room_number'])) {
            $room = Room::where('hotel_id', $hotel->id)
                ->where('room_number', $validated['room_number'])
                ->first();

            if (! $room) {
                return apiResponse('The selected room does not belong to this hotel.', 422);
            }
        }

        $guest = $this->findOrCreateGuest($hotel->id, $validated['guest_id'], $validated['channel'], $validated['guest'] ?? []);

        $reservation = $this->createOrRestoreReservation([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $room?->id,
            'reservation_id' => $validated['reservation_id'] ?? 'RES-'.strtoupper(Str::random(8)),
            'arrival_date' => $validated['arrival_date'],
            'departure_date' => $validated['departure_date'],
            'status' => $validated['status'] ?? ReservationStatus::PENDING->value,
            'adults' => $validated['adults'] ?? 1,
            'children' => $validated['children'] ?? 0,
            'source' => $validated['channel'],
            'special_requests' => $validated['special_requests'] ?? null,
            'reservation_value' => $validated['reservation_value'] ?? 0,
            'currency' => $validated['currency'] ?? $hotel->currency,
        ]);

        $this->syncRoomOccupancy($reservation);

        return apiResponse('Reservation created successfully.', 201, $reservation->load(['hotel', 'guest', 'room']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        $reservation->load(['hotel', 'guest', 'room']);
        return apiResponse('Reservation fetched successfully.', 200, $reservation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('update', $reservation);

        $validated = $request->validated();

        if (array_key_exists('hotel_id', $validated) && $validated['hotel_id'] !== $reservation->hotel_id) {
            return apiResponse('Reassigning a reservation to a different hotel is not allowed.', 422);
        }

        if ($error = $this->guardHotelScopedReferences($validated, $reservation->hotel_id)) {
            return $error;
        }

        $reservation->update($validated);

        $this->syncRoomOccupancy($reservation);

        return apiResponse('Reservation updated successfully.', 200, $reservation->load(['hotel', 'guest', 'room']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        $this->authorize('delete', $reservation);
        $reservation->delete();
        return apiResponse('Reservation deleted successfully.', 200);
    }

    /**
     * Ensure any guest_id/room_id present in a validated payload actually
     * belongs to the given hotel, since exists:guests,id / exists:rooms,id
     * alone only confirm the row exists somewhere, not that it's in scope.
     */
    private function guardHotelScopedReferences(array $validated, string $hotelId): ?JsonResponse
    {
        $invalidRelation = invalidRelation(Hotel::findOrFail($hotelId), [
            'guests' => $validated['guest_id'] ?? null,
            'rooms' => $validated['room_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse('The selected '.Str::singular($invalidRelation).' does not belong to this hotel.', 422);
        }

        return null;
    }

    /**
     * A confirmed reservation implies its room is now taken, so reflect
     * that on the room itself rather than leaving it "available".
     */
    private function syncRoomOccupancy(Reservation $reservation): void
    {
        if ($reservation->status === ReservationStatus::CONFIRMED && $reservation->room_id) {
            Room::whereKey($reservation->room_id)->update(['status' => RoomStatusesEnum::OCCUPIED->value]);
        }
    }

    /**
     * A trashed reservation is not visible through normal queries, but its
     * unique reservation_id row still exists, so blindly creating would
     * throw a duplicate-key error. Restore and update it instead.
     */
    private function createOrRestoreReservation(array $attributes): Reservation
    {
        $trashed = Reservation::onlyTrashed()->where('reservation_id', $attributes['reservation_id'])->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update($attributes);

            return $trashed;
        }

        return Reservation::create($attributes);
    }

    private function findOrCreateGuest(string $hotelId, string $externalId, string $channel, array $guestDetails): Guest
    {
        $guest = Guest::withTrashed()->firstOrCreate(
            [
                'hotel_id' => $hotelId,
                'external_id' => $externalId,
                'channel' => $channel,
            ],
            [
                'first_name' => $guestDetails['first_name'] ?? null,
                'last_name' => $guestDetails['last_name'] ?? null,
                'phone_number' => $guestDetails['phone_number'] ?? null,
                'email' => $guestDetails['email'] ?? null,
            ]
        );

        if($guest->trashed()) {
            $guest->restore();
        }
        return $guest;
    }
}
