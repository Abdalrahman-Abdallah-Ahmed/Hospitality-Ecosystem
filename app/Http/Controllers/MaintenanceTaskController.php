<?php

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Enums\RoomStatusesEnum;
use App\Http\Requests\MaintenanceTaskIndexRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Support\RequestRules\GenericQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The maintenance list (SPEC-033, FR-031): tasks for the hotel's Maintenance
 * team or in its categories, open first, most urgent first, oldest first.
 */
class MaintenanceTaskController extends Controller
{
    public function index(MaintenanceTaskIndexRequest $request): JsonResponse
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

        $teamId = $hotel->maintenance_team_id;

        // No Maintenance team means no maintenance tasks — not every task
        // without a team, which is what matching a null team would give.
        $query = Task::query()
            ->where('tasks.hotel_id', $hotel->id)
            ->when($teamId === null, fn (Builder $query) => $query->whereRaw('false'))
            ->when($teamId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('assigned_to_team_id', $teamId)
                ->orWhereHas('taskCategory', fn (Builder $category) => $category->where('team_id', $teamId))))
            ->with(['room', 'createdByUser']);

        if ($roomOutOfOrder !== null) {
            $wanted = filter_var($roomOutOfOrder, FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('room', fn (Builder $room) => $wanted
                ? $room->where('status', RoomStatusesEnum::OUT_OF_ORDER)
                : $room->where('status', '!=', RoomStatusesEnum::OUT_OF_ORDER));
        }

        if (! $request->filled('sort')) {
            $priorities = array_map(fn (Priority $priority) => $priority->value, [Priority::HIGH, Priority::NORMAL, Priority::LOW]);

            $query->orderByRaw("CASE WHEN status IN ('pending', 'in_progress') THEN 0 ELSE 1 END")
                ->orderByRaw('array_position(?::text[], priority::text)', ['{'.implode(',', $priorities).'}'])
                ->orderBy('created_at');
        }

        $tasks = GenericQuery::apply($query, $request);
        $today = CarbonImmutable::now($hotel->timezone);

        $rows = collect($tasks->items())->map(fn (Task $task) => [
            ...TaskResource::make($task)->resolve(),
            'room' => $task->room ? [
                'id' => $task->room->id,
                'room_number' => $task->room->room_number,
                'status' => $task->room->status,
                'out_of_order' => $task->room->isOutOfOrder() ? [
                    'reason' => $task->room->out_of_order_reason,
                    'expected_end_date' => $task->room->out_of_order_until?->toDateString(),
                    'overdue' => $task->room->out_of_order_until !== null
                        && $task->room->out_of_order_until->toDateString() < $today->toDateString(),
                ] : null,
            ] : null,
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
