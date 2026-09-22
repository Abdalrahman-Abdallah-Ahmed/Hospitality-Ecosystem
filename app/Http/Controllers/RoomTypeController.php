<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\RoomTypeResource;
use App\Models\RoomType;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class RoomTypeController extends Controller
{
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', RoomType::class);

        $query = RoomType::query();
        $roomTypes = GenericQuery::apply($query, $request);

        return apiResponse('Room types retrieved successfully', 200, RoomTypeResource::collection($roomTypes));
    }

    public function show(RoomType $roomType): JsonResponse
    {
        $this->authorize('view', $roomType);

        return apiResponse('Room type retrieved successfully', 200, RoomTypeResource::make($roomType));
    }

    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', RoomType::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        // The request allows null because the column has a default; a null
        // would override that default and break the NOT NULL column.
        if (array_key_exists('is_active', $validated) && $validated['is_active'] === null) {
            unset($validated['is_active']);
        }

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $roomType = new RoomType([...$validated, 'hotel_id' => $hotel->id]);

        if ($error = $this->invalidRoomType($roomType)) {
            return apiResponse($error, 422);
        }

        $roomType->save();

        return apiResponse('Room type created successfully', 201, RoomTypeResource::make($roomType->refresh()));
    }

    public function update(GenericUpdateRequest $request, RoomType $roomType): JsonResponse
    {
        $this->authorize('update', $roomType);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $roomType->fill($validated);

        if ($error = $this->invalidRoomType($roomType)) {
            return apiResponse($error, 422);
        }

        $roomType->save();

        return apiResponse('Room type updated successfully', 200, RoomTypeResource::make($roomType));
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        $this->authorize('delete', $roomType);

        if ($roomType->rooms()->exists()) {
            return apiResponse(
                'Cannot delete room type: active rooms still reference this type. Deactivate the room type instead, or delete/reassign the rooms first.',
                422,
                ['error' => 'deletion_blocked_by_rooms']
            );
        }

        $roomType->delete();

        return apiResponse('Room type deleted successfully', 200, []);
    }

    /**
     * The rules the schema-derived request cannot express: capacity bounds,
     * a non-negative price, and a name unique per hotel. Checked on the
     * filled model so an update is judged against its merged values.
     */
    private function invalidRoomType(RoomType $roomType): ?string
    {
        if ($roomType->adult_capacity < 1 || $roomType->child_capacity < 0 || $roomType->max_occupancy < 1) {
            return 'Adult capacity and maximum occupancy must be at least 1, and child capacity cannot be negative.';
        }

        if ($roomType->adult_capacity + $roomType->child_capacity > $roomType->max_occupancy) {
            return 'Adult capacity plus child capacity cannot exceed maximum occupancy.';
        }

        if ($roomType->base_price < 0) {
            return 'Base price cannot be negative.';
        }

        // Mirrors the partial unique index: live rows only, case-insensitive.
        $nameTaken = RoomType::withoutGlobalScope('hotel')
            ->where('hotel_id', $roomType->hotel_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($roomType->name)])
            ->when($roomType->exists, fn ($query) => $query->whereKeyNot($roomType->getKey()))
            ->exists();

        if ($nameTaken) {
            return 'A room type with this name already exists in this hotel.';
        }

        return null;
    }
}
