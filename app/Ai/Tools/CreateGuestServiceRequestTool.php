<?php

namespace App\Ai\Tools;

use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\Priority;
use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\CreationNotificationService;
use App\Support\Pitching\PitchTurn;
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
        private readonly ?PitchTurn $pitchTurn = null,
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
        $kind = $this->kind($request);
        $stay = $this->stayFor($request->string('room_number')->toString());

        $task = Task::make([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'reservation_id' => $this->reservation?->id,
            'stay_id' => $stay?->id,
            'room_id' => $stay ? $stay->room_id : $this->fallbackRoomId(),
            'task_category_id' => $this->taskCategoryId($request),
            'title' => $request->string('title')->toString(),
            'description' => $request->string('description')->toString(),
            'created_by' => CreatedBy::GUEST,
            'priority' => $this->priority($request),
        ]);
        $task->guest_signal = $kind;
        $task->save();

        // Something is needed or broken: nothing may be pitched in this reply.
        if ($kind === GuestSignal::SERVICE_REQUEST) {
            $this->pitchTurn?->markBlockingRequest();
        }

        // Attributed to the guest, but written by the concierge agent, so
        // admins hear about it the same as any other AI-created task.
        app(CreationNotificationService::class)->taskCreated($task, createdByAi: true);

        return "Task created (task id: {$task->id}). Staff will follow up.";
    }

    /**
     * The guest's stay the request is about (FR-019): their only room in the
     * house, or the one whose number they gave. None when they are not in
     * yet, or are in several rooms and did not say which.
     */
    private function stayFor(string $roomNumber): ?Stay
    {
        if (! $this->reservation) {
            return null;
        }

        $inHouse = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $this->reservation->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->with('room')
            ->get();

        if ($inHouse->count() === 1) {
            return $inHouse->first();
        }

        $roomNumber = trim($roomNumber);

        return $roomNumber === '' ? null : $inHouse->first(fn (Stay $stay) => $stay->room?->room_number === $roomNumber);
    }

    /**
     * Before the guest is in, the request still names the room they are
     * booked into, when there is exactly one; several rooms and none named
     * leave it to staff.
     */
    private function fallbackRoomId(): ?string
    {
        if (! $this->reservation) {
            return null;
        }

        $inHouse = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $this->reservation->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->count();

        return $inHouse > 1 ? null : $this->reservation->primaryRoomId();
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
            'kind' => $schema->string()
                ->enum([GuestSignal::SERVICE_REQUEST->value, GuestSignal::BOOKING_FOLLOW_UP->value])
                ->description('service_request: the guest needs something or something is broken. booking_follow_up: the guest is interested in an activity and staff should help them book it. When in doubt, use service_request.')
                ->default(GuestSignal::SERVICE_REQUEST->value),
            'room_number' => $schema->string()
                ->description('The room the request is for, only if the guest is staying in more than one room and said which.'),
        ];
    }

    /**
     * Only a follow-up the agent explicitly labels as one is treated as a
     * positive signal. Anything else is a service request, which keeps
     * pitching quiet — the conservative default.
     */
    private function kind(Request $request): GuestSignal
    {
        return $request->string('kind')->toString() === GuestSignal::BOOKING_FOLLOW_UP->value
            ? GuestSignal::BOOKING_FOLLOW_UP
            : GuestSignal::SERVICE_REQUEST;
    }

    /**
     * A VIP guest's request always reaches staff as high priority. This is
     * enforced here rather than in the prompt, where the model could miss it.
     */
    private function priority(Request $request): Priority
    {
        if ($this->guest->is_vip) {
            return Priority::HIGH;
        }

        return $request->enum('priority', Priority::class, Priority::NORMAL);
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
