<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Hotel;
use App\Models\Task;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tasks for the AI agents.
 *
 * The Insights agent uses it unpaged: up to 20 open tasks, most urgent first,
 * as a plain list, as it always has. The Admin AI uses it paged (SPEC-055
 * R6): filters, any status, at most 50 rows with the total.
 */
class GetTasksTool implements Tool
{
    private const UNPAGED_LIMIT = 20;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly bool $paged = false,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        if (! $this->paged) {
            return 'Retrieve open (pending or in-progress) tasks for the current hotel, including title, description, status, priority, due date, category, and who it is assigned to.';
        }

        return 'List this hotel\'s tasks: title, status, priority, due date, room, category, team and assignee. '
            .'Open tasks by default; filter by status, priority, team, assignee, category, room number or due date '
            .'range (YYYY-MM-DD). Returns at most 50 with the total.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = Task::with(['assignedToTeam', 'assignedToUser', 'taskCategory', 'room'])
            ->where('hotel_id', $this->hotel->id);

        if (! $this->paged) {
            return json_encode($query
                ->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
                ->orderByDesc('due_date')
                ->limit(self::UNPAGED_LIMIT)
                ->get()
                ->sortBy(fn (Task $task) => $this->urgency($task))
                ->map(fn (Task $task) => $this->row($task))
                ->values());
        }

        $query
            ->when($request->filled('status'),
                fn (Builder $query) => $query->where('status', $request->string('status')->toString()),
                fn (Builder $query) => $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS]))
            ->when($request->filled('priority'), fn (Builder $query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($request->filled('team'), fn (Builder $query) => $query->whereHas('assignedToTeam', fn (Builder $team) => $team->whereRaw('lower(name) = ?', [mb_strtolower($request->string('team')->toString())])))
            ->when($request->filled('assignee'), fn (Builder $query) => $query->whereHas('assignedToUser', fn (Builder $user) => $user->where('name', 'ilike', '%'.addcslashes($request->string('assignee')->toString(), '%_\\').'%')))
            ->when($request->filled('category'), fn (Builder $query) => $query->whereHas('taskCategory', fn (Builder $category) => $category->whereRaw('lower(name) = ?', [mb_strtolower($request->string('category')->toString())])))
            ->when($request->filled('room_number'), fn (Builder $query) => $query->whereHas('room', fn (Builder $room) => $room->where('room_number', $request->string('room_number')->toString())))
            ->when($request->filled('due_from'), fn (Builder $query) => $query->whereDate('due_date', '>=', $request->string('due_from')->toString()))
            ->when($request->filled('due_to'), fn (Builder $query) => $query->whereDate('due_date', '<=', $request->string('due_to')->toString()))
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->orderBy('due_date')
            ->orderBy('created_at');

        return ListResult::fromQuery($query, ListResult::limit($request), fn (Task $task) => [
            ...$this->row($task),
            'room_number' => $task->room?->room_number,
            'team' => $task->assignedToTeam?->name,
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        if (! $this->paged) {
            return [];
        }

        return [
            'status' => $schema->string()->enum(TaskStatus::class)->description('Leave out for open tasks (pending or in progress).'),
            'priority' => $schema->string()->enum(Priority::class),
            'team' => $schema->string()->description('Team name, e.g. Housekeeping.'),
            'assignee' => $schema->string()->description("Part of the assigned staff member's name."),
            'category' => $schema->string()->description('Task category name.'),
            'room_number' => $schema->string(),
            'due_from' => $schema->string()->description('Due on or after this date, YYYY-MM-DD.'),
            'due_to' => $schema->string()->description('Due on or before this date, YYYY-MM-DD.'),
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }

    private function urgency(Task $task): int
    {
        return match ($task->priority) {
            Priority::HIGH => 0,
            Priority::NORMAL => 1,
            Priority::LOW => 2,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateTimeString(),
            'category' => $task->taskCategory?->name,
            'assigned_to' => $task->assignedToUser?->name ?? $task->assignedToTeam?->name,
        ];
    }
}
