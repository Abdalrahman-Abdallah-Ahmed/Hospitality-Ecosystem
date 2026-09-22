<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\RoomType\CreateRequest;
use App\Http\Requests\RoomType\UpdateRequest;
use App\Http\Resources\RoomTypeResource;
use App\Models\Room;
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

    public function store(CreateRequest $request): JsonResponse
    {
        $this->authorize('create', RoomType::class);

        $validated = $request->validated();
        $validated['hotel_id'] = $request->user()->hotel_id;

        $roomType = RoomType::create($validated);

        return apiResponse('Room type created successfully', 201, RoomTypeResource::make($roomType));
    }

    public function update(UpdateRequest $request, RoomType $roomType): JsonResponse
    {
        $this->authorize('update', $roomType);

        $validated = $request->validated();
        $roomType->update($validated);

        return apiResponse('Room type updated successfully', 200, RoomTypeResource::make($roomType));
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        $this->authorize('delete', $roomType);

        $activeRoomsCount = Room::where('room_type_id', $roomType->id)
            ->whereNull('deleted_at')
            ->count();

        if ($activeRoomsCount > 0) {
            return apiResponse(
                'Cannot delete room type: active rooms still reference this type. Deactivate the room type instead, or delete/reassign the rooms first.',
                422,
                ['error' => 'deletion_blocked_by_rooms']
            );
        }

        $roomType->delete();

        return apiResponse('Room type deleted successfully', 200, []);
    }
}
