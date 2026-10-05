<?php

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Http\Requests\ReportIssueRequest;
use App\Http\Resources\TaskResource;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\MaintenanceService;
use Carbon\CarbonImmutable;
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

        if ($reason = $this->cannotReportOn($task, $hotel)) {
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

    /**
     * Issues are reported on a housekeeping task for a room, while it is open
     * or on the hotel day it was completed (FR-022).
     */
    private function cannotReportOn(Task $task, Hotel $hotel): ?string
    {
        $isHousekeeping = $task->housekeeping_kind !== null
            || ($task->task_category_id
                && $hotel->housekeeping_team_id
                && TaskCategory::whereKey($task->task_category_id)->value('team_id') === $hotel->housekeeping_team_id);

        if (! $isHousekeeping) {
            return 'Issues can only be reported on a housekeeping task.';
        }

        if (! $task->room_id) {
            return 'This task is not for a room.';
        }

        if ($task->isOpen()) {
            return null;
        }

        $completedToday = $task->status === TaskStatus::COMPLETED
            && $task->completed_at !== null
            && $task->completed_at->copy()->setTimezone($hotel->timezone)->toDateString()
                === CarbonImmutable::now($hotel->timezone)->toDateString();

        return $completedToday ? null : 'Issues can be reported on a housekeeping task while it is open or on the day it was completed.';
    }
}
