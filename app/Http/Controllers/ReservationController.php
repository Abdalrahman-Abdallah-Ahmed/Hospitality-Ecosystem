<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Requests\WhatsAppReservationStoreRequest;
use App\Models\Guest;
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

        $reservation = Reservation::create($validated);

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

        $reservation = Reservation::create([
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
        if (! empty($validated['guest_id']) && ! Guest::where('id', $validated['guest_id'])->where('hotel_id', $hotelId)->exists()) {
            return apiResponse('The selected guest does not belong to this hotel.', 422);
        }

        if (! empty($validated['room_id']) && ! Room::where('id', $validated['room_id'])->where('hotel_id', $hotelId)->exists()) {
            return apiResponse('The selected room does not belong to this hotel.', 422);
        }

        return null;
    }

    private function findOrCreateGuest(string $hotelId, string $externalId, string $channel, array $guestDetails): Guest
    {
        return Guest::firstOrCreate(
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
    }
}
