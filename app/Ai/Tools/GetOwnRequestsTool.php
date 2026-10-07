<?php

namespace App\Ai\Tools;

use App\Enums\TaskStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The guest's own open requests (SPEC-007 FR-021), so the Concierge can tell
 * them where things stand and add to a request instead of filing it twice.
 * Guest-facing only: no team, assignee, description, priority or notes.
 */
class GetOwnRequestsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly Guest $guest,
    ) {}

    public function description(): Stringable|string
    {
        return "List the guest's own open requests (service, maintenance, room change, booking cancellation, request for a person) with what each is about and whether it has been received or is in progress. Use it when the guest asks about a request, and before filing a new one, to add to an open request about the same problem instead.";
    }

    public function handle(Request $request): Stringable|string
    {
        $requests = Task::ownedByGuest($this->hotel, $this->guest)
            ->guestRequests()
            ->open()
            ->with(['room' => fn ($query) => $query->withoutGlobalScope('hotel')])
            ->orderBy('created_at')
            ->get();

        if ($requests->isEmpty()) {
            return 'The guest has no open requests.';
        }

        return json_encode($requests->map(fn (Task $task) => [
            'id' => $task->id,
            'kind' => $task->guest_signal->value,
            'title' => $task->title,
            'status' => $task->status === TaskStatus::IN_PROGRESS ? 'in progress' : 'received',
            'created_at' => $task->created_at->setTimezone($this->hotel->timezone ?? config('app.timezone'))->toIso8601String(),
            'room_number' => $task->room?->room_number,
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
