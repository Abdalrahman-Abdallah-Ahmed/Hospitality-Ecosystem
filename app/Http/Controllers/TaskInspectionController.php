<?php

namespace App\Http\Controllers;

use App\Enums\InspectionResult;
use App\Http\Requests\InspectionRequest;
use App\Http\Resources\RoomResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;

/**
 * Completes an inspection task with its result (SPEC-030, FR-005): a pass
 * makes the room inspected, a fail sends it back to dirty with a re-clean.
 */
class TaskInspectionController extends Controller
{
    public function store(InspectionRequest $request, Task $task, HousekeepingService $housekeeping): JsonResponse
    {
        $this->authorize('update', $task);

        $result = $housekeeping->recordInspection(
            $task,
            InspectionResult::from($request->validated('result')),
            $request->validated('note'),
        );

        return apiResponse('Inspection recorded.', 200, [
            'task' => TaskResource::make($result['task']),
            'room' => RoomResource::make($result['room']->withReadiness()),
            'cleaning_task' => $result['cleaning_task'] ? TaskResource::make($result['cleaning_task']) : null,
        ]);
    }
}
