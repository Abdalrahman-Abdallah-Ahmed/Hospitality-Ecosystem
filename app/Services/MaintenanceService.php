<?php

namespace App\Services;

use App\Enums\ActorKind;
use App\Enums\CleaningReason;
use App\Enums\CreatedBy;
use App\Enums\HousekeepingCause;
use App\Enums\Priority;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatusesEnum;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Exceptions\HousekeepingException;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\EventLogger;
use App\Support\Housekeeping\HousekeepingDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Whether a room can be sold at all (SPEC-033/035): taking it out of order,
 * returning it to service, and turning a room issue found during housekeeping
 * into a maintenance task.
 *
 * Out of order is open-ended: the expected end date is for staff only, and
 * the room comes back only through returnToService() — completing its
 * maintenance task never reopens it (FR-019). A room with a guest in it is
 * never taken out of order (FR-017). Locks the room before any task.
 */
class MaintenanceService
{
    public function __construct(
        private HousekeepingService $housekeeping,
        private CreationNotificationService $notifications,
    ) {}

    /**
     * @return array{room: Room, changed: bool, affected_lines: Collection<int, array<string, mixed>>}
     */
    public function takeOutOfOrder(Room $room, ?User $actor, string $reason, ?string $expectedEndDate = null, ?Task $task = null): array
    {
        return DB::transaction(function () use ($room, $actor, $reason, $expectedEndDate, $task): array {
            $room = $this->lockRoom($room->id);

            if ($room->isOutOfOrder()) {
                return ['room' => $room, 'changed' => false, 'affected_lines' => collect()];
            }

            $inHouse = Stay::withoutGlobalScope('hotel')
                ->where('room_id', $room->id)
                ->where('status', StayStatus::IN_HOUSE)
                ->first();

            if ($inHouse) {
                throw HousekeepingException::because(
                    'room',
                    "Room {$room->room_number} has an in-house guest (stay {$inHouse->id}). Move the guest first.",
                );
            }

            $room->forceFill([
                'status' => RoomStatusesEnum::OUT_OF_ORDER,
                'out_of_order_reason' => $reason,
                'out_of_order_since' => now(),
                'out_of_order_until' => $expectedEndDate,
                'out_of_order_by_user_id' => $actor?->id,
                'out_of_order_task_id' => $task?->id,
            ])->save();

            return ['room' => $room, 'changed' => true, 'affected_lines' => $this->affectedLines($room)];
        });
    }

    /**
     * Changes the reason or the expected end date of an out-of-order room.
     *
     * @param  array{reason?: string, expected_end_date?: ?string}  $changes
     */
    public function updateOutOfOrder(Room $room, array $changes): Room
    {
        return DB::transaction(function () use ($room, $changes): Room {
            $room = $this->lockRoom($room->id);

            if (! $room->isOutOfOrder()) {
                throw HousekeepingException::because('room', "Room {$room->room_number} is not out of order.");
            }

            if (isset($changes['reason'])) {
                $room->out_of_order_reason = $changes['reason'];
            }

            // A null end date is a real change: "no longer expected back on a date".
            if (array_key_exists('expected_end_date', $changes)) {
                $room->out_of_order_until = $changes['expected_end_date'];
            }

            $room->save();

            return $room;
        });
    }

    /**
     * The repair is done: the room is available again, dirty, with a
     * cleaning task (FR-020).
     *
     * @return array{room: Room, cleaning_task: Task}
     */
    public function returnToService(Room $room, ?string $note = null): array
    {
        return DB::transaction(function () use ($room, $note): array {
            $room = $this->lockRoom($room->id);

            if (! $room->isOutOfOrder()) {
                throw HousekeepingException::because('room', "Room {$room->room_number} is not out of order.");
            }

            $room->auditExtras = ['reason' => $note];

            try {
                $room->forceFill([
                    'status' => RoomStatusesEnum::AVAILABLE,
                    'out_of_order_reason' => null,
                    'out_of_order_since' => null,
                    'out_of_order_until' => null,
                    'out_of_order_by_user_id' => null,
                    'out_of_order_task_id' => null,
                ])->save();
            } finally {
                $room->auditExtras = [];
            }

            $cleaning = $this->housekeeping->roomNeedsCleaning($room, CleaningReason::RETURN_TO_SERVICE, HousekeepingCause::RETURN_TO_SERVICE);

            return ['room' => $room->fresh(), 'cleaning_task' => $cleaning];
        });
    }

    /**
     * A room issue found on a housekeeping task becomes one maintenance task
     * for the Maintenance team (FR-023). "Room cannot be sold" also takes the
     * room out of order when the reporter may do that and nobody is in it
     * (FR-024); otherwise the task is still created and the result says why
     * the room stayed on sale. The same report twice gives the same task
     * (FR-025).
     *
     * @return array{task: Task, created: bool, out_of_order: array{requested: bool, applied: bool, reason: ?string}}
     */
    public function reportIssue(Task $source, User $reporter, string $description, Priority $priority, bool $roomUnsellable): array
    {
        return DB::transaction(function () use ($source, $reporter, $description, $priority, $roomUnsellable): array {
            $room = $this->lockRoom($source->room_id);
            $source = Task::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($source->id);
            $normalized = Str::lower(trim($description));

            $existing = Task::withoutGlobalScope('hotel')
                ->where('source_task_id', $source->id)
                ->open()
                ->get()
                ->first(fn (Task $task) => Str::lower(trim((string) $task->description)) === $normalized);

            if ($existing) {
                return [
                    'task' => $existing,
                    'created' => false,
                    'out_of_order' => ['requested' => $roomUnsellable, 'applied' => false, 'reason' => 'This issue was already reported.'],
                ];
            }

            $hotel = Hotel::findOrFail($room->hotel_id);
            $defaults = HousekeepingDefaults::for($hotel);
            $byAi = EventLogger::currentActorKind() === ActorKind::AI_AGENT;

            $task = Task::withoutGlobalScope('hotel')->create([
                'hotel_id' => $room->hotel_id,
                'room_id' => $room->id,
                'stay_id' => $source->stay_id,
                'reservation_id' => $source->reservation_id,
                'guest_id' => $source->guest_id,
                'assigned_to_team_id' => $defaults['maintenanceTeam']?->id,
                // A category belongs to a team; without the team the task
                // would carry a category that contradicts its (empty) team.
                'task_category_id' => $defaults['maintenanceTeam'] ? $defaults['maintenanceCategory']?->id : null,
                'created_by_user_id' => $reporter->id,
                'title' => "Room {$room->room_number}: ".Str::limit($description, 60),
                'description' => $description,
                'created_by' => $byAi ? CreatedBy::AI : CreatedBy::MANUAL,
                'status' => TaskStatus::PENDING,
                'priority' => $priority,
            ]);

            $task->forceFill(['source_task_id' => $source->id])->save();

            $this->notifications->taskCreated($task, createdByAi: $byAi);

            if ($gap = $this->notifications->routingGap($task)) {
                $this->notifications->routingProblem($hotel, $gap, $task);
            }

            $outOfOrder = ['requested' => $roomUnsellable, 'applied' => false, 'reason' => null];

            if ($roomUnsellable) {
                $outOfOrder = $this->outOfOrderFromIssue($room, $reporter, $description, $task);
            }

            return ['task' => $task, 'created' => true, 'out_of_order' => $outOfOrder];
        });
    }

    /**
     * Whether completing this task leaves an out-of-order room ready to be
     * returned to service (FR-019).
     */
    public function roomReadyToReturn(Task $task): bool
    {
        return $task->status === TaskStatus::COMPLETED
            && Room::withoutGlobalScope('hotel')
                ->where('out_of_order_task_id', $task->id)
                ->where('status', RoomStatusesEnum::OUT_OF_ORDER)
                ->exists();
    }

    /**
     * @return array{requested: bool, applied: bool, reason: ?string}
     */
    private function outOfOrderFromIssue(Room $room, User $reporter, string $description, Task $task): array
    {
        if ($room->isOutOfOrder()) {
            return ['requested' => true, 'applied' => false, 'reason' => 'The room is already out of order.'];
        }

        if (! Gate::forUser($reporter)->allows('setOutOfOrder', $room)) {
            return ['requested' => true, 'applied' => false, 'reason' => 'You do not have permission to take rooms out of order.'];
        }

        try {
            $this->takeOutOfOrder($room, $reporter, $description, null, $task);
        } catch (HousekeepingException $exception) {
            return ['requested' => true, 'applied' => false, 'reason' => $exception->getMessage()];
        }

        return ['requested' => true, 'applied' => true, 'reason' => null];
    }

    /**
     * Live lines assigned to this room that still have nights ahead, so staff
     * can move them to another room.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function affectedLines(Room $room): Collection
    {
        $hotel = Hotel::findOrFail($room->hotel_id);
        $today = CarbonImmutable::now($hotel->timezone)->toDateString();

        return ReservationRoom::withoutGlobalScope('hotel')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->where('reservation_rooms.room_id', $room->id)
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->whereNull('reservations.deleted_at')
            ->whereNotIn('reservations.status', [ReservationStatus::CANCELLED->value, ReservationStatus::CHECKED_OUT->value])
            ->whereDate('reservations.departure_date', '>', $today)
            ->orderBy('reservations.arrival_date')
            ->get([
                'reservation_rooms.id as reservation_room_id',
                'reservations.id as reservation_id',
                'reservations.reservation_id as reservation_code',
                'reservations.arrival_date',
                'reservations.departure_date',
            ])
            ->map(fn ($line) => [
                'reservation_room_id' => $line->reservation_room_id,
                'reservation_id' => $line->reservation_id,
                'reservation_code' => $line->reservation_code,
                'arrival_date' => CarbonImmutable::parse($line->arrival_date)->toDateString(),
                'departure_date' => CarbonImmutable::parse($line->departure_date)->toDateString(),
            ]);
    }

    private function lockRoom(string $roomId): Room
    {
        return Room::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($roomId);
    }
}
