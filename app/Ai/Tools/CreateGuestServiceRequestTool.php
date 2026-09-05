<?php

namespace App\Ai\Tools;

use App\Enums\CreatedBy;
use App\Enums\Priority;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\TaskCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateGuestServiceRequestTool implements Tool
{
    public function __construct(
        private readonly Guest $guest,
        private readonly Hotel $hotel,
        private readonly ?Reservation $reservation = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Create a task for hotel staff related to the guest — either a service request made on the guest's behalf (e.g. extra towels, a maintenance issue, a housekeeping request), or a follow-up task asking staff to contact the guest (e.g. the guest showed strong interest in a recommended activity and would like help booking it).";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $task = Task::create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'reservation_id' => $this->reservation?->id,
            'room_id' => $this->reservation?->room->id,
            'task_category_id' => $this->taskCategoryId($request),
            'title' => $request->string('title')->toString(),
            'description' => $request->string('description')->toString(),
            'created_by' => CreatedBy::GUEST,
            'priority' => $request->enum('priority', Priority::class, Priority::NORMAL),
        ]);

        return "Task created (task id: {$task->id}). Staff will follow up.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short title for the task.')->required(),
            'description' => $schema->string()->description('What the guest needs, or why staff should follow up with them.')->required(),
            'priority' => $schema->string()
                ->enum(Priority::class)
                ->description("The task's urgency. Use 'high' when the guest showed strong interest in a recommended activity and staff should follow up promptly to help them book it before the window passes; otherwise 'normal'.")
                ->default(Priority::NORMAL->value),
            'task_category_id' => $schema->string()
                ->description('The id of the task category this request falls under, if one clearly fits. Leave unset if none does.'),
        ];
    }

    /**
     * The model may name a category that doesn't exist or belongs to
     * another hotel — fall back to uncategorized rather than erroring.
     */
    private function taskCategoryId(Request $request): ?string
    {
        if (! $request->filled('task_category_id')) {
            return null;
        }

        return TaskCategory::where('hotel_id', $this->hotel->id)
            ->find($request->string('task_category_id')->toString())
            ?->id;
    }
}
