<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\GuestResource;
use App\Models\Guest;
use App\Services\Guests\GuestRegistrar;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class GuestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        $query = Guest::with(['hotel', 'reservations', 'conversations', 'stays']);

        $guests = GenericQuery::apply($query, $request);

        return apiResponse('Guests fetched successfully.', 200, GuestResource::collection($guests));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request, GuestRegistrar $registrar): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $validated = $request->validated();

        $hotel = resolveHotel($request->user(), $validated['hotel_id'] ?? null);
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        // Duplicate rules live in GuestRegistrar, shared with the Admin AI.
        // An existing person is returned as if created, as before.
        ['guest' => $guest] = $registrar->register($hotel, unsetAttributes($validated, ['hotel_id']));

        return apiResponse('Guest created successfully.', 201, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Display the specified resource.
     */
    public function show(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        return apiResponse('Guest fetched successfully.', 200, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Guest $guest, GuestRegistrar $registrar): JsonResponse
    {
        $this->authorize('update', $guest);

        $registrar->update($guest, unsetAttributes($request->validated(), ['hotel_id']));

        return apiResponse('Guest updated successfully.', 200, GuestResource::make($guest->load(['hotel', 'reservations', 'conversations', 'stays'])));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Guest $guest): JsonResponse
    {
        $this->authorize('delete', $guest);

        $guest->delete();

        return apiResponse('Guest deleted successfully.', 200);
    }
}
