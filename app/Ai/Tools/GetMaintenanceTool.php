<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Enums\RoomStatusesEnum;
use App\Enums\TaskStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Task;
use App\Services\Reports\MaintenanceList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Maintenance for the Admin AI: the maintenance list the maintenance screen
 * shows (SPEC-033), plus every room that is out of order right now.
 */
class GetMaintenanceTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Maintenance: the Maintenance team\'s tasks (open ones first, most urgent first) with room, priority, '
            .'status, assignee and age, and every room currently out of order with its reason and expected end date. '
            .'Open tasks only unless "include_closed" is true. Returns at most 50 tasks with the total.';
    }

    public function handle(Request $request): Stringable|string
    {
        $list = app(MaintenanceList::class);
        $query = $list->defaultOrder($list->query($this->hotel)->with(['assignedToUser', 'assignedToTeam']));

        if (! $request->boolean('include_closed')) {
            $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS]);
        }

        $tasks = ListResult::queryPayload($query, ListResult::limit($request), fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'room' => $list->roomSummary($task, $this->hotel),
            'assigned_to' => $task->assignedToUser?->name ?? $task->assignedToTeam?->name,
            'age_hours' => (int) $task->created_at->diffInHours(now()),
        ]);

        $outOfOrder = Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('status', RoomStatusesEnum::OUT_OF_ORDER)
            ->orderBy('room_number')
            ->get()
            ->map(fn (Room $room) => [
                'room_number' => $room->room_number,
                'reason' => $room->out_of_order_reason,
                'expected_end_date' => $room->out_of_order_until?->toDateString(),
            ]);

        return json_encode([...$tasks, 'out_of_order_rooms' => $outOfOrder->values()->all()], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_closed' => $schema->boolean()->description('Also list completed and cancelled maintenance tasks.'),
            'limit' => $schema->integer()->description('At most this many tasks, 1-50. Default 50.'),
        ];
    }
}
