<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\Priority;
use App\Models\Hotel;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reports a room issue found during housekeeping as a maintenance task, as
 * the housekeeping task screen does (MaintenanceService::reportIssue), with
 * the same rule on which tasks an issue can be reported on.
 */
class ReportTaskIssueTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Report a room issue found on a housekeeping task (by task id) to maintenance: what is wrong, how urgent, '
            .'and whether the room cannot be sold until it is fixed (which takes it out of order). A repeat of the same '
            .'issue is reported as already known.';
    }

    public function handle(Request $request): Stringable|string
    {
        $task = $this->findTask($request->string('task_id')->toString());

        if (! $task) {
            return 'This hotel has no task with that id.';
        }

        $data = [
            'description' => $request->string('description')->toString(),
            'priority' => $request->string('priority')->toString() ?: null,
            'room_unsellable' => $request->boolean('room_unsellable'),
        ];

        $maintenance = app(MaintenanceService::class);

        if ($reason = $maintenance->issueRefusal($task, $this->hotel)) {
            return $this->notDone($reason);
        }

        $result = $this->attempt(function () use ($task, $data, $maintenance) {
            Validator::make($data, [
                'description' => ['required', 'string', 'max:2000'],
                'priority' => ['nullable', Rule::enum(Priority::class)],
                'room_unsellable' => ['boolean'],
            ])->validate();

            return $maintenance->reportIssue(
                $task,
                $this->user,
                $data['description'],
                Priority::tryFrom((string) $data['priority']) ?? Priority::NORMAL,
                $data['room_unsellable'],
            );
        });

        if (is_string($result)) {
            return $result;
        }

        if (! $result['created']) {
            return $this->alreadyExists($result['task']->id, 'maintenance task for this issue');
        }

        return $this->done(
            ['maintenance_task_id' => $result['task']->id],
            ['issue' => 'reported', 'out_of_order' => (bool) $result['out_of_order']],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()->description('The housekeeping task the issue was found on.')->required(),
            'description' => $schema->string()->description('What is wrong.')->required(),
            'priority' => $schema->string()->enum(Priority::class),
            'room_unsellable' => $schema->boolean()->description('True when the room cannot be sold until fixed.'),
        ];
    }
}
