<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\GlopalIndexRequest;
use App\Http\Requests\ReservationStoreRequest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\WhatsAppDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GlopalIndexRequest $request)
    {
        $reservations = Reservation::with(['hotel', 'guest', 'room'])
        ->where('hotel_id', $request->user()->hotel_id)
        ->get();
        return apiResponse('Reservations fetched successfully.', 200, $reservations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ReservationStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $device = WhatsAppDevice::where('phone_number', $validated['phone_number'])->first();

        if (! $device || $device->status !== 'active') {
            return apiResponse('WhatsApp device not paired.', 403);
        }

        $hotel = $device->hotel;

        if (! empty($validated['room_id'])) {
            $roomBelongsToHotel = Room::where('id', $validated['room_id'])
                ->where('hotel_id', $hotel->id)
                ->exists();

            if (! $roomBelongsToHotel) {
                return apiResponse('The selected room does not belong to this hotel.', 422);
            }
        }

        $guest = $this->findOrCreateGuest($hotel->id, $validated['guest_id'], $validated['channel'], $validated['guest'] ?? []);

        $reservation = Reservation::create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'room_id' => $validated['room_id'] ?? null,
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

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        //
    }
}
