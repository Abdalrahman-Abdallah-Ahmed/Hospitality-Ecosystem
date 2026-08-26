<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\Guest;
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

        $query = Guest::with(['hotel', 'reservations', 'conversations']);

        $guests = GenericQuery::apply($query, $request);

        return apiResponse('Guests fetched successfully.', 200, $guests);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $validated = $request->validated();

        $hotel = resolveHotel($request->user(), $validated['hotel_id'] ?? null);
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $validated = unsetAttributes($validated, ['hotel_id']);

        // The (hotel_id, channel, external_id) unique index is composite, so the
        // generic schema-derived rules can't validate it. A trashed guest's row
        // still occupies that key, so look for it and restore instead of
        // letting Guest::create() hit a duplicate-key error.
        if (! empty($validated['channel']) && ! empty($validated['external_id'])) {
            $existing = Guest::withTrashed()
                ->where('hotel_id', $hotel->id)
                ->where('channel', $validated['channel'])
                ->where('external_id', $validated['external_id'])
                ->first();

            if ($existing && ! $existing->trashed()) {
                return apiResponse('A guest with this channel and external id already exists.', 422);
            }

            if ($existing) {
                $existing->restore();
                $existing->update([...$validated, 'hotel_id' => $hotel->id]);

                return apiResponse('Guest created successfully.', 201, $existing->load(['hotel', 'reservations', 'conversations']));
            }
        }

        $guest = Guest::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Guest created successfully.', 201, $guest->load(['hotel', 'reservations', 'conversations']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        return apiResponse('Guest fetched successfully.', 200, $guest->load(['hotel', 'reservations', 'conversations']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Guest $guest): JsonResponse
    {
        $this->authorize('update', $guest);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $guest->update($validated);

        return apiResponse('Guest updated successfully.', 200, $guest->load(['hotel', 'reservations', 'conversations']));
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
