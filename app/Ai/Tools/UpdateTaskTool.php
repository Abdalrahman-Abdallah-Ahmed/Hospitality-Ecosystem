<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Moves a task along — who has it, how urgent, when it is due, its status —
 * through TaskCommands, the task endpoint's own rules. A person or team is
 * named, not guessed: no match or several matches come back to the admin
 * instead of a pick (FR-025). A guest's cancellation request cannot be
 * closed here; it is answered on its booking, as on the task screen.
 */
class UpdateTaskTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Update a task, found by its id: assign it to a staff member or a team (by name or id), change its status, '
            .'priority, due date or description. Send only what the admin asked to change. If a name matches nobody or '
            .'several people, the candidates come back; ask the admin which one.';
    }

    public function handle(Request $request): Stringable|string
    {
        $task = $this->findTask($request->string('task_id')->toString());

        if (! $task) {
            return 'This hotel has no task with that id.';
        }

        $changes = [];

        if ($request->filled('assignee')) {
            $user = $this->findStaff($request->string('assignee')->toString());

            if ($user === null) {
                return 'Nobody in this hotel matches "'.$request->string('assignee')->toString().'". Nothing was changed.';
            }

            if ($user instanceof Collection) {
                return $this->ambiguous('staff member', $user->map(fn (User $match) => ['id' => $match->id, 'name' => $match->name]));
            }

            $changes['assigned_to_user_id'] = $user->id;
        }

        if ($request->filled('team')) {
            $team = $this->findTeam($request->string('team')->toString());

            if ($team === null) {
                return 'This hotel has no team called "'.$request->string('team')->toString().'". Nothing was changed.';
            }

            if ($team instanceof Collection) {
                return $this->ambiguous('team', $team->map(fn (Team $match) => ['id' => $match->id, 'name' => $match->name]));
            }

            $changes['assigned_to_team_id'] = $team->id;
        }

        foreach (['status' => TaskStatus::class, 'priority' => Priority::class] as $field => $enum) {
            if ($request->filled($field)) {
                $value = $enum::tryFrom($request->string($field)->toString());

                if (! $value) {
                    return "\"{$request->string($field)->toString()}\" is not a valid {$field}.";
                }

                $changes[$field] = $value->value;
            }
        }

        foreach (['due_date', 'description'] as $field) {
            if ($request->filled($field)) {
                $changes[$field] = $request->string($field)->toString();
            }
        }

        if ($changes === []) {
            return 'Nothing to change: say what to update.';
        }

        $result = $this->attempt(fn () => app(TaskCommands::class)->update($task, $changes));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['task_id' => $task->id],
            $changes,
            $result['room_ready_to_return'] ? 'The repair is finished: the room can now be returned to service.' : null,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()->description("The task's id. Look it up first.")->required(),
            'assignee' => $schema->string()->description('The staff member to assign, by name or id.'),
            'team' => $schema->string()->description('The team to assign, by name or id.'),
            'status' => $schema->string()->enum(TaskStatus::class),
            'priority' => $schema->string()->enum(Priority::class),
            'due_date' => $schema->string()->description('YYYY-MM-DD or YYYY-MM-DD HH:MM.'),
            'description' => $schema->string(),
        ];
    }
}
