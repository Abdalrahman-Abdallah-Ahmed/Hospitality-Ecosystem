<?php

namespace App\Http\Controllers;

use App\Http\Requests\OutOfOrderRequest;
use App\Http\Requests\ReturnToServiceRequest;
use App\Http\Resources\RoomResource;
use App\Http\Resources\TaskResource;
use App\Models\Room;
use App\Models\Task;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;

/**
 * Taking a room out of order and returning it to service (SPEC-033). Out of
 * order is open-ended: the room comes back only through return to service.
 */
class RoomOutOfOrderController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function store(OutOfOrderRequest $request, Room $room): JsonResponse
    {
        $this->authorize('setOutOfOrder', $room);

        $taskId = $request->validated('task_id');

        if ($invalid = invalidRelation($room->hotel, ['tasks' => $taskId])) {
            return apiResponse("The selected {$invalid} does not belong to you.", 403);
        }

        $result = $this->maintenance->takeOutOfOrder(
            $room,
            $request->user(),
            $request->validated('reason'),
            $request->validated('expected_end_date'),
            $taskId ? Task::find($taskId) : null,
        );

        return apiResponse(
            $result['changed'] ? 'Room taken out of order.' : 'Room is already out of order.',
            200,
            [
                'room' => RoomResource::make($result['room']->load('roomType')->withReadiness()),
                'changed' => $result['changed'],
                'affected_lines' => $result['affected_lines']->values(),
            ],
        );
    }

    public function update(OutOfOrderRequest $request, Room $room): JsonResponse
    {
        $this->authorize('setOutOfOrder', $room);

        $room = $this->maintenance->updateOutOfOrder($room, $request->validated());

        return apiResponse('Out-of-order details updated.', 200, [
            'room' => RoomResource::make($room->load('roomType')->withReadiness()),
        ]);
    }

    public function returnToService(ReturnToServiceRequest $request, Room $room): JsonResponse
    {
        $this->authorize('setOutOfOrder', $room);

        $result = $this->maintenance->returnToService($room, $request->validated('note'));

        return apiResponse('Room returned to service.', 200, [
            'room' => RoomResource::make($result['room']->load('roomType')->withReadiness()),
            'cleaning_task' => TaskResource::make($result['cleaning_task']),
        ]);
    }
}
