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

        $guests = GenericQuery::apply(
            Guest::with(['hotel', 'reservations', 'conversations'])
                ->where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Guests fetched successfully.', 200, $guests);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $validated = $request->validated();

        if (($validated['hotel_id'] ?? null) !== $request->user()->hotel?->id) {
            return apiResponse('The selected hotel does not belong to you.', 403);
        }

        $guest = Guest::create($validated);

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

        $validated = $request->validated();

        if (array_key_exists('hotel_id', $validated) && $validated['hotel_id'] !== $guest->hotel_id) {
            return apiResponse('Reassigning a guest to a different hotel is not allowed.', 422);
        }

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
