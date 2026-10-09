<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceTaskIndexRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\Reports\MaintenanceList;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * The maintenance list (SPEC-033, FR-031): tasks for the hotel's Maintenance
 * team or in its categories, open first, most urgent first, oldest first.
 * The query lives in MaintenanceList, shared with the Admin AI.
 */
class MaintenanceTaskController extends Controller
{
    public function index(MaintenanceTaskIndexRequest $request, MaintenanceList $list): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $hotel = resolveHotel($request->user(), $request->input('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $filters = (array) $request->input('filter', []);
        $roomOutOfOrder = $filters['room_out_of_order'] ?? null;
        unset($filters['room_out_of_order']);
        $request->merge(['filter' => $filters]);

        $query = $list->query($hotel, $roomOutOfOrder === null ? null : filter_var($roomOutOfOrder, FILTER_VALIDATE_BOOLEAN));

        if (! $request->filled('sort')) {
            $list->defaultOrder($query);
        }

        $tasks = GenericQuery::apply($query, $request);

        $rows = collect($tasks->items())->map(fn (Task $task) => [
            ...TaskResource::make($task)->resolve(),
            'room' => $list->roomSummary($task, $hotel),
            'reporter' => $task->createdByUser ? ['id' => $task->createdByUser->id, 'name' => $task->createdByUser->name] : null,
            'age_hours' => (int) $task->created_at->diffInHours(now()),
        ]);

        return apiResponse('Maintenance tasks fetched successfully.', 200, [
            'data' => $rows->values(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }
}
