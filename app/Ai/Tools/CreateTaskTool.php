<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\CreatedBy;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Staff-side task creation, for the admin advisor.
 *
 * The concierge has CreateGuestServiceRequestTool, which creates a task on a
 * guest's behalf and is attributed to the guest. This one is the admin asking
 * for work to be done — attributed to the AI acting for a staff member, and
 * able to assign a team or a person, which the guest-side tool deliberately
 * cannot do.
 *
 * It creates through TaskCommands, the task endpoint's own rules (team from
 * category, category must belong to the team, housekeeping hooks,
 * notifications). Before that it refuses an obvious duplicate: an open task
 * with the same room, category and title from the last day (R9) — a model
 * retry or a repeated message, not a second job.
 */
class CreateTaskTool implements Tool
{
    use AdminToolSupport;

    private const DUPLICATE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $creator,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a task for hotel staff — maintenance, housekeeping, or any other work the admin wants recorded. Can be assigned to a team or a specific staff member, and linked to a room. If the same open task already exists it is reported instead of created twice.';
    }

    public function handle(Request $request): Stringable|string
    {
        $title = $request->string('title')->trim()->toString();

        if ($title === '') {
            return 'A task title is required.';
        }

        $roomId = $this->roomId($request);
        $categoryId = $this->belongingId($request, 'task_category_id', TaskCategory::class);

        if ($existing = $this->openDuplicate($title, $roomId, $categoryId)) {
            return $this->alreadyExists($existing->id, "open task \"{$existing->title}\"");
        }

        $attributes = [
            'room_id' => $roomId,
            'task_category_id' => $categoryId,
            'assigned_to_team_id' => $this->belongingId($request, 'assigned_to_team_id', Team::class),
            'assigned_to_user_id' => $this->assignedUserId($request),
            'created_by_user_id' => $this->creator->getKey(),
            'title' => $title,
            'description' => $request->string('description')->toString() ?: null,
            // The row was written by the agent, not typed by the admin. The
            // admin who asked for it is kept separately in created_by_user_id,
            // so "who wanted this" and "what wrote it" stay distinguishable.
            'created_by' => CreatedBy::AI,
            'status' => TaskStatus::PENDING,
            'priority' => $request->enum('priority', Priority::class, Priority::NORMAL),
            'due_date' => $request->filled('due_date') ? $request->string('due_date')->toString() : null,
        ];

        $task = $this->attempt(fn () => app(TaskCommands::class)->create($this->hotel, $attributes, createdByAi: true));

        if (is_string($task)) {
            return $task;
        }

        return $this->done(
            ['task_id' => $task->id],
            ['task' => 'created', 'priority' => $task->priority->value, 'assigned_to_team_id' => $task->assigned_to_team_id],
            "Task \"{$task->title}\" created (task id: {$task->id}), priority {$task->priority->value}.",
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short title for the task.')->required(),
            'description' => $schema->string()->description('What needs doing, in enough detail for whoever picks it up.'),
            'priority' => $schema->string()
                ->enum(Priority::class)
                ->description("The task's urgency. Use high only when the admin indicates it is urgent.")
                ->default(Priority::NORMAL->value),
            'due_date' => $schema->string()->description('When the task is due, YYYY-MM-DD or YYYY-MM-DD HH:MM. Leave unset if the admin does not say.'),
            'room_number' => $schema->string()->description('The room this task concerns, if any (e.g. "203").'),
            'task_category_id' => $schema->string()->description('The id of the task category, if one clearly fits. Look the categories up first rather than guessing an id.'),
            'assigned_to_team_id' => $schema->string()->description('The id of the team responsible, if the admin names one.'),
            'assigned_to_user_id' => $schema->string()->description('The id of a specific staff member to assign, if the admin names one.'),
        ];
    }

    private function openDuplicate(string $title, ?string $roomId, ?string $categoryId): ?Task
    {
        return Task::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])
            ->where('room_id', $roomId)
            ->where('task_category_id', $categoryId)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->first();
    }

    private function roomId(Request $request): ?string
    {
        if (! $request->filled('room_number')) {
            return null;
        }

        return $this->findRoomByNumber($request->string('room_number')->toString())?->id;
    }

    /**
     * Resolve an id only if the record belongs to this hotel.
     *
     * A model can produce a plausible-looking uuid from anywhere in the
     * conversation, and assigning one hotel's task to another hotel's team
     * would put a staff member's work list in front of the wrong property.
     * An id that does not belong here is dropped, not honoured — the task is
     * still created, just unassigned, which is visible and fixable.
     *
     * @param  class-string<Model>  $model
     */
    private function belongingId(Request $request, string $field, string $model): ?string
    {
        if (! $request->filled($field)) {
            return null;
        }

        return $this->findOwn($model, $request->string($field)->toString())?->getKey();
    }

    /**
     * Users are not hotel-scoped the way tasks are — they may belong through
     * hotel_id or through the account — so the check is explicit rather than
     * reusing belongingId().
     */
    private function assignedUserId(Request $request): ?string
    {
        if (! $request->filled('assigned_to_user_id')) {
            return null;
        }

        return User::query()
            ->where('hotel_id', $this->hotel->id)
            ->find($request->string('assigned_to_user_id')->toString())
            ?->getKey();
    }
}
