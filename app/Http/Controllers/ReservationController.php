<?php

namespace App\Http\Controllers;

use App\Enums\ReservationChannels;
use App\Http\Requests\ImportReservationsRequest;
use App\Http\Requests\ReservationIndexRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Imports\ReservationsImport;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\Audit\EventLogger;
use App\Support\RequestRules\GenericQuery;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReservationController extends Controller
{
    private const RELATIONS = ['hotel', 'guest', 'reservationRooms.roomType', 'reservationRooms.room'];

    /**
     * Display a listing of the resource.
     */
    public function index(ReservationIndexRequest $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $query = Reservation::with(self::RELATIONS);

        // Room type and room live on the lines, not the reservation: match any
        // live line, then hand the rest of the filters to the generic query.
        $filters = (array) $request->input('filter', []);

        foreach (ReservationIndexRequest::LINE_FILTERS as $column) {
            if (array_key_exists($column, $filters)) {
                $value = $filters[$column];
                $query->whereHas('reservationRooms', fn ($lines) => $lines->active()->where($column, $value));
                unset($filters[$column]);
            }
        }

        $request->merge(['filter' => $filters]);

        $reservations = GenericQuery::apply($query, $request);

        return apiResponse('Reservations fetched successfully.', 200, ReservationResource::collection($reservations));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $this->authorize('create', Reservation::class);

        $validated = $request->validated();

        $hotel = resolveHotel($request->user(), $validated['hotel_id'] ?? null);
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $validated = unsetAttributes($validated, ['hotel_id', 'rooms', 'capacity_override']);

        if ($error = $this->guardHotelScopedReferences($validated, $hotel->id)) {
            return $error;
        }

        if (ReservationCreator::isReservationIdInUse($hotel->id, $validated['reservation_id'])) {
            throw $this->reservationIdTaken();
        }

        $reservation = ReservationCreator::create(
            [...$validated, 'hotel_id' => $hotel->id],
            $request->input('rooms', []),
            $request->boolean('capacity_override'),
        );

        return apiResponse('Reservation created successfully.', 201, ReservationResource::make($reservation->load(self::RELATIONS)));
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        $reservation->load(self::RELATIONS);

        return apiResponse('Reservation fetched successfully.', 200, ReservationResource::make($reservation));
    }

    /**
     * Update the specified resource in storage. `rooms`, when sent, is the
     * full desired list of live lines; the raw input is passed on so an
     * explicit `room_id: null` (clear) stays distinct from a missing one.
     */
    public function update(UpdateReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('update', $reservation);

        $validated = unsetAttributes($request->validated(), ['hotel_id', 'rooms', 'capacity_override']);

        if ($error = $this->guardHotelScopedReferences($validated, $reservation->hotel_id)) {
            return $error;
        }

        if (isset($validated['reservation_id']) && $this->usedByAnotherReservation($reservation, $validated['reservation_id'])) {
            throw $this->reservationIdTaken();
        }

        ReservationCreator::update(
            $reservation,
            $validated,
            $request->has('rooms') ? $request->input('rooms') : null,
            $request->boolean('capacity_override'),
        );

        return apiResponse('Reservation updated successfully.', 200, ReservationResource::make($reservation->load(self::RELATIONS)));
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
     * Bulk-import reservations (and their guests) from an uploaded
     * spreadsheet. Always scoped to the uploader's own hotel, never a
     * hotel_id from the file, same as index()/CreateReservationTool.
     */
    public function import(ImportReservationsRequest $request): JsonResponse
    {
        $this->authorize('import', Reservation::class);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $import = new ReservationsImport($hotel);

        // One summary audit event instead of thousands of per-row create events.
        EventLogger::withoutRecording(fn () => Excel::import($import, $request->file('file')));

        $summary = [
            'imported' => $import->imported,
            'skipped' => $import->skipped,
        ];

        EventLogger::record($hotel, 'reservations_imported', changes: $summary);

        return apiResponse('Reservations imported successfully.', 200, $summary);
    }

    /**
     * The booking channels the system recognises, served from the enum itself
     * so a channel picker is built from the server's list rather than a
     * hard-coded copy that quietly drifts when a channel is added.
     *
     * The list is the same for every account — there is no model behind it and
     * nothing hotel-specific to authorise against, so it carries no policy
     * check beyond the authentication its route already requires.
     */
    public function availableChannels(): JsonResponse
    {
        return apiResponse(
            'Available channels fetched successfully.',
            200,
            ReservationChannels::cases(),
        );
    }

    /**
     * Ensure a guest_id present in a validated payload actually belongs to
     * the given hotel, since exists:guests,id alone only confirms the row
     * exists somewhere, not that it's in scope. Room types and rooms on the
     * lines are checked by ReservationRoomSync.
     */
    private function guardHotelScopedReferences(array $validated, string $hotelId): ?JsonResponse
    {
        $invalidRelation = invalidRelation(Hotel::findOrFail($hotelId), [
            'guests' => $validated['guest_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse('The selected '.Str::singular($invalidRelation).' does not belong to this hotel.', 422);
        }

        return null;
    }

    /**
     * On update a soft-deleted reservation still holds its code in the unique
     * index, so it counts as taken — unlike on create, where it is restored.
     */
    private function usedByAnotherReservation(Reservation $reservation, string $reservationId): bool
    {
        return Reservation::withTrashed()
            ->where('hotel_id', $reservation->hotel_id)
            ->where('reservation_id', $reservationId)
            ->whereKeyNot($reservation->getKey())
            ->exists();
    }

    /**
     * Same shape and wording the column's old single-column unique rule
     * produced, so clients reading `errors.reservation_id` see no change.
     */
    private function reservationIdTaken(): ValidationException
    {
        return ValidationException::withMessages([
            'reservation_id' => 'The reservation id has already been taken.',
        ]);
    }
}
