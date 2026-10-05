<?php

namespace App\Http\Controllers;

use App\Enums\HousekeepingStatusesEnum;
use App\Http\Requests\UpdateHousekeepingStatusRequest;
use App\Http\Resources\RoomResource;
use App\Http\Resources\TaskResource;
use App\Models\Room;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;

/**
 * A manual correction of a room's housekeeping status (SPEC-030, FR-009).
 * Tasks are left alone and listed in the response.
 */
class RoomHousekeepingController extends Controller
{
    public function update(UpdateHousekeepingStatusRequest $request, Room $room, HousekeepingService $housekeeping): JsonResponse
    {
        $this->authorize('updateHousekeepingStatus', $room);

        $result = $housekeeping->setManually(
            $room,
            HousekeepingStatusesEnum::from($request->validated('housekeeping_status')),
            $request->validated('reason'),
        );

        return apiResponse('Housekeeping status updated.', 200, [
            'room' => RoomResource::make($result['room']->load('roomType')->withReadiness()),
            'changed' => $result['changed'],
            'open_housekeeping_tasks' => TaskResource::collection($result['open_tasks']),
        ]);
    }
}
