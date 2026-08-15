<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\RequestRules\GenericQuery;
use App\Support\Reservations\ReservationCreator;
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

        $reservation = ReservationCreator::create($validated);

        ReservationCreator::syncRoomOccupancy($reservation);

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

        ReservationCreator::syncRoomOccupancy($reservation);

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
}
