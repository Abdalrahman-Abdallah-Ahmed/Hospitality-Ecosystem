<?php

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Http\Requests\ReportIssueRequest;
use App\Http\Resources\TaskResource;
use App\Models\Hotel;
use App\Models\Task;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;

/**
 * A room issue reported on a housekeeping task becomes a maintenance task
 * (SPEC-035, FR-022–025), and can take the room out of order.
 */
class TaskIssueController extends Controller
{
    public function store(ReportIssueRequest $request, Task $task, MaintenanceService $maintenance): JsonResponse
    {
        $this->authorize('update', $task);

        $hotel = Hotel::findOrFail($task->hotel_id);

        if ($reason = $maintenance->issueRefusal($task, $hotel)) {
            return apiResponse($reason, 422);
        }

        $result = $maintenance->reportIssue(
            $task,
            $request->user(),
            $request->validated('description'),
            Priority::tryFrom((string) $request->validated('priority')) ?? Priority::NORMAL,
            (bool) $request->validated('room_unsellable', false),
        );

        return apiResponse(
            $result['created'] ? 'Issue reported to maintenance.' : 'This issue was already reported.',
            $result['created'] ? 201 : 200,
            [
                'maintenance_task' => TaskResource::make($result['task']),
                'created' => $result['created'],
                'out_of_order' => $result['out_of_order'],
            ],
        );
    }
}
