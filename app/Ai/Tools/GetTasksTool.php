<?php

namespace App\Ai\Tools;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Hotel;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetTasksTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieve open (pending or in-progress) tasks for the current hotel, including title, description, status, priority, due date, category, and who it is assigned to.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $tasks = Task::with(['assignedToTeam', 'assignedToUser', 'taskCategory'])
            ->where('hotel_id', $this->hotel->id)
            ->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
            ->orderByDesc('due_date')
            ->limit(20)
            ->get()
            ->sortBy(fn (Task $task) => match ($task->priority) {
                Priority::HIGH => 0,
                Priority::NORMAL => 1,
                Priority::LOW => 2,
            })
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_date' => $task->due_date?->toDateTimeString(),
                'category' => $task->taskCategory?->name,
                'assigned_to' => $task->assignedToUser?->name ?? $task->assignedToTeam?->name,
            ])
            ->values();

        return json_encode($tasks);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
