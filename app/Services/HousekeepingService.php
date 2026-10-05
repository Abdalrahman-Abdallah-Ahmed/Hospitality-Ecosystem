<?php

namespace App\Services;

use App\Enums\ActorKind;
use App\Enums\CleaningReason;
use App\Enums\CreatedBy;
use App\Enums\HousekeepingCause;
use App\Enums\HousekeepingKind;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\InspectionResult;
use App\Enums\Priority;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Exceptions\HousekeepingException;
use App\Models\Hotel;
use App\Models\HousekeepingDayRun;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Support\Audit\EventLogger;
use App\Support\Housekeeping\HousekeepingDefaults;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a room's housekeeping status (SPEC-030, research R3).
 *
 * Cleaning and inspection tasks drive it: starting a clean puts the room in
 * cleaning, completing it makes the room clean (and, when the hotel inspects,
 * creates the inspection task), an inspection pass makes it inspected and a
 * fail sends it back to dirty with a re-clean. Check-out, the start-of-day
 * process, return to service and a manual correction set it directly. Every
 * change is audited with its cause, task and actor (room.housekeeping_changed).
 *
 * Locks the room row before the task row, the same order as check-in and
 * check-out, and re-reads after locking. A room has at most one open task of
 * each kind (FR-007): automatic steps reuse the open one, and anything else
 * that would make a second one is rejected.
 */
class HousekeepingService
{
    public function __construct(private CreationNotificationService $notifications) {}

    /**
     * Whether the room can be given to a guest as far as housekeeping goes
     * (FR-006): inspected, or clean when the hotel doesn't inspect — or when
     * it was cleaned before inspection was turned on.
     */
    public function isReady(Room $room, ?Hotel $hotel = null): bool
    {
        if ($room->housekeeping_status === HousekeepingStatusesEnum::INSPECTED) {
            return true;
        }

        if ($room->housekeeping_status !== HousekeepingStatusesEnum::CLEAN) {
            return false;
        }

        $hotel ??= Hotel::find($room->hotel_id);

        if (! $hotel?->inspection_required) {
            return true;
        }

        return $hotel->inspection_required_since !== null
            && $room->housekeeping_status_changed_at !== null
            && $room->housekeeping_status_changed_at->lt($hotel->inspection_required_since);
    }

    /**
     * Locks the rooms a task change will touch, in id order, before the task
     * itself is written — so the lock order is always room, then task.
     *
     * @param  array<int, string|null>  $roomIds
     */
    public function lockRooms(array $roomIds): void
    {
        $ids = array_values(array_unique(array_filter($roomIds)));
        sort($ids);

        if ($ids !== []) {
            Room::withoutGlobalScope('hotel')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * After a task is created: classify it, reject a second open task of its
     * kind, and move the room if it was created already started or finished.
     */
    public function taskCreated(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            // Column defaults (a pending status) are not on a just-created model.
            $task->refresh();
            $this->lockRooms([$task->room_id]);
            $this->classify($task);

            if ($task->housekeeping_kind && $task->room_id && $task->status !== TaskStatus::PENDING) {
                $this->applyTransition($task, TaskStatus::PENDING);
            }
        });
    }

    /**
     * After a task is updated. `$before` holds its original attributes.
     *
     * @param  array<string, mixed>  $before
     */
    public function taskChanged(Task $task, array $before): void
    {
        DB::transaction(function () use ($task, $before): void {
            $this->lockRooms([$before['room_id'] ?? null, $task->room_id]);

            $oldRoomId = $before['room_id'] ?? null;
            $oldStatus = $this->statusOf($before['status'] ?? null);
            $oldKind = $task->housekeeping_kind;

            if (($before['task_category_id'] ?? null) !== $task->task_category_id) {
                $this->classify($task);

                // An in-progress clean moved to another category stops being
                // a clean, so its room must not stay in `cleaning`.
                if ($oldKind === HousekeepingKind::CLEANING
                    && $task->housekeeping_kind !== HousekeepingKind::CLEANING
                    && $oldStatus === TaskStatus::IN_PROGRESS
                    && $oldRoomId) {
                    $this->releaseRoom($oldRoomId, $task);
                }
            }

            if ($oldRoomId !== $task->room_id) {
                $this->taskMovedRooms($task, $oldRoomId, $oldKind, $oldStatus);

                return;
            }

            if (! $task->housekeeping_kind || ! $task->room_id || $oldStatus === $task->status) {
                return;
            }

            $this->assertNoOtherOpen($task);

            if ($task->housekeeping_kind === HousekeepingKind::INSPECTION
                && $task->status === TaskStatus::COMPLETED
                && $task->inspection_result === null) {
                throw HousekeepingException::because('status', 'Use the inspection action to complete an inspection task.');
            }

            $this->applyTransition($task, $oldStatus);
        });
    }

    /**
     * The task left one room and joined another: undo what it was doing to
     * the first, then apply it to the second.
     */
    private function taskMovedRooms(Task $task, ?string $oldRoomId, ?HousekeepingKind $oldKind, ?TaskStatus $oldStatus): void
    {
        if ($oldRoomId && $oldKind && $oldStatus === TaskStatus::IN_PROGRESS) {
            $this->releaseRoom($oldRoomId, $task);
        }

        $this->assertNoOtherOpen($task);

        if ($task->housekeeping_kind && $task->room_id && $task->status !== TaskStatus::PENDING) {
            $this->applyTransition($task, TaskStatus::PENDING);
        }
    }

    /**
     * Before a task is deleted: an in-progress clean stops, so the room goes
     * back to dirty.
     */
    public function taskRemoved(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            $this->lockRooms([$task->room_id]);

            if ($task->housekeeping_kind === HousekeepingKind::CLEANING
                && $task->room_id
                && $task->status === TaskStatus::IN_PROGRESS) {
                $this->releaseRoom($task->room_id, $task);
            }
        });
    }

    /**
     * A guest checked out of the room: dirty, and one cleaning task (an open
     * stay-over task becomes the check-out clean). Housekeeping status is
     * independent of the room status, so an out-of-order room is dirtied
     * too. The caller holds the room lock.
     */
    public function roomVacated(Room $room, Stay $stay): Task
    {
        $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::CHECK_OUT);

        return $this->ensureCleaningTask($room, CleaningReason::CHECK_OUT, $stay);
    }

    /**
     * The room needs cleaning: dirty, and one cleaning task.
     */
    public function roomNeedsCleaning(Room $room, CleaningReason $reason, HousekeepingCause $cause, ?Stay $stay = null): Task
    {
        return DB::transaction(function () use ($room, $reason, $cause, $stay): Task {
            $room = $this->lockRoom($room->id);
            $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, $cause);

            return $this->ensureCleaningTask($room, $reason, $stay);
        });
    }

    /**
     * The room's open cleaning task, or a new one in the hotel's cleaning
     * category for its Housekeeping team (FR-007, FR-014). The caller holds
     * the room lock.
     */
    public function ensureCleaningTask(
        Room $room,
        CleaningReason $reason,
        ?Stay $stay = null,
        ?CarbonInterface $due = null,
        ?string $description = null,
    ): Task {
        $open = $this->openTask($room->id, HousekeepingKind::CLEANING);

        if ($open) {
            if ($reason === CleaningReason::CHECK_OUT && $open->cleaning_reason === CleaningReason::STAY_OVER) {
                $open->forceFill(['cleaning_reason' => CleaningReason::CHECK_OUT])->save();
            }

            return $open;
        }

        $hotel = Hotel::findOrFail($room->hotel_id);
        $defaults = HousekeepingDefaults::for($hotel);

        $task = Task::withoutGlobalScope('hotel')->create([
            'hotel_id' => $room->hotel_id,
            'room_id' => $room->id,
            'reservation_id' => $stay?->reservation_id,
            'stay_id' => $stay?->id,
            'guest_id' => $stay?->guest_id,
            'assigned_to_team_id' => $defaults['housekeepingTeam']?->id,
            'task_category_id' => $defaults['cleaningCategory']?->id,
            'title' => $this->cleaningTitle($room, $reason),
            'description' => $description,
            'created_by' => CreatedBy::SYSTEM,
            'status' => TaskStatus::PENDING,
            'priority' => Priority::NORMAL,
            'due_date' => $due,
        ]);

        $task->forceFill([
            'housekeeping_kind' => HousekeepingKind::CLEANING,
            'cleaning_reason' => $reason,
        ])->save();

        $this->announce($task, $hotel);

        return $task;
    }

    /**
     * Completes an inspection task with its result (FR-005). A pass makes the
     * room inspected; a fail sends it back to dirty with a re-clean task that
     * carries the inspector's note.
     *
     * @return array{task: Task, room: Room, cleaning_task: ?Task}
     */
    public function recordInspection(Task $task, InspectionResult $result, ?string $note): array
    {
        return DB::transaction(function () use ($task, $result, $note): array {
            $room = $task->room_id ? $this->lockRoom($task->room_id) : null;
            $task = Task::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($task->id);

            if ($task->housekeeping_kind !== HousekeepingKind::INSPECTION || ! $room) {
                throw HousekeepingException::because('task', 'This is not an inspection task for a room.');
            }

            if ($task->status === TaskStatus::COMPLETED && $task->inspection_result === $result) {
                return ['task' => $task, 'room' => $room, 'cleaning_task' => null];
            }

            if (! $task->isOpen()) {
                throw HousekeepingException::because('task', 'This inspection task is not open.');
            }

            $task->forceFill([
                'status' => TaskStatus::COMPLETED,
                'inspection_result' => $result,
                'inspection_note' => $note,
            ])->save();

            if ($result === InspectionResult::PASS) {
                $this->setStatus($room, HousekeepingStatusesEnum::INSPECTED, HousekeepingCause::INSPECTION, $task);

                return ['task' => $task, 'room' => $room, 'cleaning_task' => null];
            }

            $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::INSPECTION, $task, $note);
            $cleaning = $this->ensureCleaningTask($room, CleaningReason::RE_CLEAN, description: "Failed inspection: {$note}");

            return ['task' => $task, 'room' => $room, 'cleaning_task' => $cleaning];
        });
    }

    /**
     * Staff correct a room's housekeeping status by hand (FR-009). Tasks are
     * left alone and listed, so the person can close them if they are done.
     *
     * @return array{room: Room, open_tasks: Collection<int, Task>, changed: bool}
     */
    public function setManually(Room $room, HousekeepingStatusesEnum $to, string $reason): array
    {
        return DB::transaction(function () use ($room, $to, $reason): array {
            $room = $this->lockRoom($room->id);
            $changed = $this->setStatus($room, $to, HousekeepingCause::MANUAL, null, $reason);

            $openTasks = Task::withoutGlobalScope('hotel')
                ->where('room_id', $room->id)
                ->whereNotNull('housekeeping_kind')
                ->open()
                ->get();

            return ['room' => $room, 'open_tasks' => $openTasks, 'changed' => $changed];
        });
    }

    /**
     * The start of a hotel day (FR-012–014): every stay-over room that is not
     * out of order becomes dirty and gets one stay-over cleaning task. Runs
     * once per hotel and day — the run row is inserted first, so a second or
     * concurrent run finds it and does nothing. Rooms departing that day are
     * left to check-out; a guest still in after their departure date has
     * slept in the room and gets a clean like any stay-over.
     */
    public function startDay(Hotel $hotel, string $day): ?HousekeepingDayRun
    {
        return DB::transaction(function () use ($hotel, $day): ?HousekeepingDayRun {
            $inserted = DB::table('housekeeping_day_runs')->insertOrIgnore([
                'id' => (string) str()->uuid(),
                'hotel_id' => $hotel->id,
                'day' => $day,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            $due = CarbonImmutable::parse($day, $hotel->timezone)->endOfDay();
            $dirtied = 0;
            $created = 0;

            $stays = Stay::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel->id)
                ->where('status', StayStatus::IN_HOUSE)
                ->whereNotNull('room_id')
                ->whereDate('planned_departure_date', '!=', $day)
                ->orderBy('room_id')
                ->get()
                ->unique('room_id');

            foreach ($stays as $stay) {
                $room = $this->lockRoom($stay->room_id);

                if ($room->isOutOfOrder()) {
                    continue;
                }

                if (! in_array($room->housekeeping_status, [HousekeepingStatusesEnum::DIRTY, HousekeepingStatusesEnum::CLEANING], true)) {
                    $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::START_OF_DAY);
                    $dirtied++;
                }

                $task = $this->ensureCleaningTask($room, CleaningReason::STAY_OVER, $stay, $due);

                if ($task->wasRecentlyCreated) {
                    $created++;
                }
            }

            $run = HousekeepingDayRun::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel->id)
                ->whereDate('day', $day)
                ->firstOrFail();

            $run->update(['rooms_dirtied' => $dirtied, 'tasks_created' => $created]);

            return $run;
        });
    }

    /**
     * What a task is to housekeeping, from the hotel's settings: cleaning,
     * inspection, or nothing (R4).
     */
    public function kindFor(Hotel $hotel, ?string $categoryId): ?HousekeepingKind
    {
        return match (true) {
            $categoryId === null => null,
            $categoryId === $hotel->cleaning_task_category_id => HousekeepingKind::CLEANING,
            $categoryId === $hotel->inspection_task_category_id => HousekeepingKind::INSPECTION,
            default => null,
        };
    }

    /**
     * Sets the task's kind from its category; a second open task of that
     * kind for the same room is refused.
     */
    private function classify(Task $task): void
    {
        $hotel = Hotel::findOrFail($task->hotel_id);
        $kind = $this->kindFor($hotel, $task->task_category_id);

        if ($kind === $task->housekeeping_kind) {
            return;
        }

        $task->housekeeping_kind = $kind;
        $task->cleaning_reason = $kind === HousekeepingKind::CLEANING
            ? ($task->cleaning_reason ?? CleaningReason::MANUAL)
            : null;

        $this->assertNoOtherOpen($task);

        $task->save();
    }

    private function assertNoOtherOpen(Task $task): void
    {
        if (! $task->housekeeping_kind || ! $task->room_id || ! $task->isOpen()) {
            return;
        }

        $other = $this->openTask($task->room_id, $task->housekeeping_kind, except: $task->id);

        if ($other) {
            throw HousekeepingException::because(
                'task_category_id',
                "Room already has an open {$task->housekeeping_kind->value} task ({$other->id}).",
            );
        }
    }

    /**
     * The R5 table: what a cleaning or inspection task's status change does
     * to its room. The caller holds the room lock.
     */
    private function applyTransition(Task $task, ?TaskStatus $from): void
    {
        $room = $this->lockRoom($task->room_id);

        if ($task->housekeeping_kind === HousekeepingKind::INSPECTION) {
            // Only the inspection action completes one; cancelling leaves the
            // room clean, and nothing else moves the room.
            return;
        }

        match ($task->status) {
            TaskStatus::IN_PROGRESS => $this->setStatus($room, HousekeepingStatusesEnum::CLEANING, HousekeepingCause::TASK, $task),
            TaskStatus::COMPLETED => $this->cleaningCompleted($room, $task),
            TaskStatus::PENDING => $from === TaskStatus::COMPLETED || $room->housekeeping_status === HousekeepingStatusesEnum::CLEANING
                ? $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::TASK, $task)
                : null,
            TaskStatus::CANCELLED => $from === TaskStatus::IN_PROGRESS && $room->housekeeping_status === HousekeepingStatusesEnum::CLEANING
                ? $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::TASK, $task)
                : null,
        };
    }

    private function cleaningCompleted(Room $room, Task $task): void
    {
        $this->setStatus($room, HousekeepingStatusesEnum::CLEAN, HousekeepingCause::TASK, $task);

        $hotel = Hotel::findOrFail($room->hotel_id);

        if ($hotel->inspection_required) {
            $this->ensureInspectionTask($room, $hotel);
        }
    }

    private function ensureInspectionTask(Room $room, Hotel $hotel): Task
    {
        $open = $this->openTask($room->id, HousekeepingKind::INSPECTION);

        if ($open) {
            return $open;
        }

        $defaults = HousekeepingDefaults::for($hotel);

        $task = Task::withoutGlobalScope('hotel')->create([
            'hotel_id' => $room->hotel_id,
            'room_id' => $room->id,
            'assigned_to_team_id' => $defaults['housekeepingTeam']?->id,
            'task_category_id' => $defaults['inspectionCategory']?->id,
            'title' => "Inspect room {$room->room_number}",
            'created_by' => CreatedBy::SYSTEM,
            'status' => TaskStatus::PENDING,
            'priority' => Priority::NORMAL,
        ]);

        $task->forceFill(['housekeeping_kind' => HousekeepingKind::INSPECTION])->save();

        $this->announce($task, $hotel);

        return $task;
    }

    /**
     * A clean that stopped half-way: the room is dirty again.
     */
    private function releaseRoom(string $roomId, Task $task): void
    {
        $room = $this->lockRoom($roomId);

        if ($room->housekeeping_status === HousekeepingStatusesEnum::CLEANING) {
            $this->setStatus($room, HousekeepingStatusesEnum::DIRTY, HousekeepingCause::TASK, $task);
        }
    }

    /**
     * Writes the status through the model, so the change is audited as
     * room.housekeeping_changed with its cause. Nothing is written when the
     * status is unchanged.
     */
    private function setStatus(
        Room $room,
        HousekeepingStatusesEnum $to,
        HousekeepingCause $cause,
        ?Task $task = null,
        ?string $reason = null,
    ): bool {
        if ($room->housekeeping_status === $to) {
            return false;
        }

        $room->auditExtras = ['cause' => $cause, 'task_id' => $task?->id, 'reason' => $reason];

        try {
            $room->forceFill([
                'housekeeping_status' => $to,
                'housekeeping_status_changed_at' => now(),
            ])->save();
        } finally {
            $room->auditExtras = [];
        }

        return true;
    }

    private function lockRoom(string $roomId): Room
    {
        return Room::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($roomId);
    }

    private function openTask(string $roomId, HousekeepingKind $kind, ?string $except = null): ?Task
    {
        return Task::withoutGlobalScope('hotel')
            ->where('room_id', $roomId)
            ->where('housekeeping_kind', $kind)
            ->open()
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->first();
    }

    private function announce(Task $task, Hotel $hotel): void
    {
        $this->notifications->taskCreated($task, createdByAi: EventLogger::currentActorKind() === ActorKind::AI_AGENT);

        if ($gap = $this->notifications->routingGap($task)) {
            $this->notifications->routingProblem($hotel, $gap, $task);
        }
    }

    private function cleaningTitle(Room $room, CleaningReason $reason): string
    {
        return match ($reason) {
            CleaningReason::CHECK_OUT => "Clean room {$room->room_number} after check-out",
            CleaningReason::STAY_OVER => "Stay-over clean room {$room->room_number}",
            CleaningReason::RE_CLEAN => "Re-clean room {$room->room_number}",
            CleaningReason::RETURN_TO_SERVICE => "Clean room {$room->room_number} after repair",
            CleaningReason::MANUAL => "Clean room {$room->room_number}",
        };
    }

    private function statusOf(mixed $status): ?TaskStatus
    {
        return match (true) {
            $status instanceof TaskStatus => $status,
            is_string($status) => TaskStatus::tryFrom($status),
            default => null,
        };
    }
}
