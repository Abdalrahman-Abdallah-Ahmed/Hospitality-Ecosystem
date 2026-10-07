<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ResolvesGuestStay;
use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Enums\Priority;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\CreationNotificationService;
use App\Services\GuestRequestService;
use App\Services\HousekeepingService;
use App\Support\Pitching\PitchTurn;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateGuestServiceRequestTool implements Tool
{
    use ResolvesGuestStay;

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
        return "Create a task for hotel staff related to the guest: a service request made on the guest's behalf (e.g. extra towels, a taxi, a housekeeping request), a maintenance request when something in their room is broken or not working (air conditioning, plumbing, lights, TV, door lock…), or a follow-up task asking staff to contact the guest (e.g. the guest showed strong interest in a recommended activity and would like help booking it). Check the guest's open requests first: if they are describing the same problem again, pass that request's id as add_to_request_id instead of filing a new one.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $kind = $this->kind($request);
        $roomNumber = $request->string('room_number')->toString();

        // A follow-up is a positive signal, not a service: it needs no stay.
        if ($kind->isComplaint() && ($refusal = $this->requiresActiveReservation())) {
            return $refusal;
        }

        // More detail on a request the guest already has open (R14).
        if ($request->filled('add_to_request_id')) {
            $open = app(GuestRequestService::class)->appendDetail(
                $request->string('add_to_request_id')->toString(),
                trim($request->string('title')->toString().': '.$request->string('description')->toString(), ': '),
                $this->guest,
                $this->hotel,
                $kind,
            );

            if ($open) {
                // The guest is still waiting on something: no pitch in this reply.
                if ($kind->isComplaint()) {
                    $this->pitchTurn?->markBlockingRequest();
                }

                return "Added to your existing request (task id: {$open->id}). Staff will see the update.";
            }
        }

        // A repair needs the right room: ask rather than guess.
        if ($kind === GuestSignal::MAINTENANCE_REQUEST && ($ask = $this->askWhichRoom($roomNumber))) {
            return $ask;
        }

        $stay = $this->stayFor($roomNumber);
        $roomId = $stay ? $stay->room_id : $this->fallbackRoomId();
        [$categoryId, $teamId] = $this->routing($request, $kind);
        $housekeeping = app(HousekeepingService::class);

        $task = Task::make([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'reservation_id' => ($this->activeReservation() ?? $this->reservation)?->id,
            'stay_id' => $stay?->id,
            'room_id' => $roomId,
            'task_category_id' => $categoryId,
            'assigned_to_team_id' => $teamId,
            'title' => $request->string('title')->toString(),
            'description' => $request->string('description')->toString(),
            'created_by' => CreatedBy::GUEST,
            'priority' => $this->priority($request),
        ]);
        $task->guest_signal = $kind;

        $isCleaning = $roomId && $housekeeping->kindFor($this->hotel, $categoryId) === HousekeepingKind::CLEANING;

        // One open clean per room (FR-007): a guest asking for a clean that
        // is already scheduled is told so instead of getting a second one.
        // Checked under the room lock, so a clean the start-of-day job is
        // creating at the same moment is seen rather than collided with.
        $scheduled = DB::transaction(function () use ($task, $housekeeping, $isCleaning): ?Task {
            $housekeeping->lockRooms([$task->room_id]);

            $open = $isCleaning
                ? Task::where('room_id', $task->room_id)->where('housekeeping_kind', HousekeepingKind::CLEANING)->open()->first()
                : null;

            if ($open) {
                return $open;
            }

            $task->save();
            $housekeeping->taskCreated($task);

            return null;
        });

        if ($scheduled) {
            return "A cleaning of this room is already scheduled (task id: {$scheduled->id}). Staff will take care of it.";
        }

        // Something is needed or broken: nothing may be pitched in this reply.
        if ($kind->isComplaint()) {
            $this->pitchTurn?->markBlockingRequest();
        }

        // Attributed to the guest, but written by the concierge agent, so
        // admins hear about it the same as any other AI-created task.
        app(CreationNotificationService::class)->taskCreated($task, createdByAi: true);

        if ($kind === GuestSignal::MAINTENANCE_REQUEST) {
            $room = $task->room()->withoutGlobalScope('hotel')->value('room_number');

            return $room
                ? "Maintenance request filed for room {$room} (task id: {$task->id}). The maintenance team has it."
                : "Maintenance request filed (task id: {$task->id}). The maintenance team has it.";
        }

        return "Task created (task id: {$task->id}). Staff will follow up.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short, guest-facing title for the task, e.g. "Extra towels" or "Air conditioning not cooling".')->required(),
            'description' => $schema->string()->description('What the guest needs or what is wrong, in their words, or why staff should follow up with them.')->required(),
            'priority' => $schema->string()
                ->enum(Priority::class)
                ->description("The task's urgency. Use 'high' when the guest showed strong interest in a recommended activity and staff should follow up promptly to help them book it before the window passes; otherwise 'normal'.")
                ->default(Priority::NORMAL->value),
            'task_category_id' => $schema->string()
                ->description('For a service_request only: the id of the task category it falls under, if one clearly fits. Leave unset if none does. Ignored for maintenance requests, which always go to the maintenance team.'),
            'kind' => $schema->string()
                ->enum([GuestSignal::SERVICE_REQUEST->value, GuestSignal::MAINTENANCE_REQUEST->value, GuestSignal::BOOKING_FOLLOW_UP->value])
                ->description('service_request: the guest needs something (towels, a taxi, a cleaning). maintenance_request: something in the room is broken or not working. booking_follow_up: the guest is interested in an activity and staff should help them book it. When in doubt between service and follow-up, use service_request.')
                ->default(GuestSignal::SERVICE_REQUEST->value),
            'room_number' => $schema->string()
                ->description('The room the request is for, only if the guest is staying in more than one room and said which.'),
            'add_to_request_id' => $schema->string()
                ->description("The id of one of the guest's open requests of the same kind (from the open-requests tool) that this message adds to, when the guest is describing the same need again. Leave unset for a new request."),
        ];
    }

    /**
     * Only a follow-up the agent explicitly labels as one is treated as a
     * positive signal. Anything else is a request, which keeps pitching
     * quiet — the conservative default.
     */
    private function kind(Request $request): GuestSignal
    {
        return match ($request->string('kind')->toString()) {
            GuestSignal::BOOKING_FOLLOW_UP->value => GuestSignal::BOOKING_FOLLOW_UP,
            GuestSignal::MAINTENANCE_REQUEST->value => GuestSignal::MAINTENANCE_REQUEST,
            default => GuestSignal::SERVICE_REQUEST,
        };
    }

    /**
     * The category and team a request goes to (SPEC-007 R3). A repair always
     * goes to the hotel's Maintenance category and team, whatever category the
     * model named; anything else goes to the team of the category it chose,
     * or to no team when none fits.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function routing(Request $request, GuestSignal $kind): array
    {
        if ($kind === GuestSignal::MAINTENANCE_REQUEST) {
            return [$this->hotel->maintenance_task_category_id, $this->hotel->maintenance_team_id];
        }

        $categoryId = $this->taskCategoryId($request);

        return [$categoryId, $categoryId ? TaskCategory::whereKey($categoryId)->value('team_id') : null];
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
