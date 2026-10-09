<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\CreationNotificationService;
use App\Services\HousekeepingService;
use App\Services\MaintenanceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing tasks, for the staff API and the Admin AI alike
 * (SPEC-055 R4): the same hotel checks, team and category rules, stay
 * linking, housekeeping hooks and notifications on both paths. A refusal is
 * a DomainRuleException carrying the status the endpoint always returned.
 */
class TaskCommands
{
    public function __construct(
        private readonly HousekeepingService $housekeeping,
        private readonly MaintenanceService $maintenance,
        private readonly CreationNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  without hotel_id and guest_id
     *
     * @throws DomainRuleException
     */
    public function create(Hotel $hotel, array $validated, bool $createdByAi = false): Task
    {
        $invalidRelation = invalidRelation($hotel, [
            'rooms' => $validated['room_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
            'teams' => $validated['assigned_to_team_id'] ?? null,
            'users' => $validated['assigned_to_user_id'] ?? null,
            'taskCategories' => $validated['task_category_id'] ?? null,
            'stays' => $validated['stay_id'] ?? null,
        ]) ?? invalidRelation($hotel, [
            'users' => $validated['created_by_user_id'] ?? null,
        ]);

        if ($invalidRelation) {
            throw new DomainRuleException("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $this->teamFromCategory($validated);

        if (! $this->taskCategoryBelongsToTeam($validated['assigned_to_team_id'] ?? null, $validated['task_category_id'] ?? null)) {
            throw new DomainRuleException('The selected task category does not belong to the chosen team.', 403);
        }

        $this->applyStay($validated);

        $task = DB::transaction(function () use ($validated, $hotel): Task {
            $this->housekeeping->lockRooms([$validated['room_id'] ?? null]);

            $task = Task::create([
                ...$validated,
                'hotel_id' => $hotel->id,
                'guest_id' => $this->guestIdForReservation($validated['reservation_id'] ?? null),
            ]);

            $this->housekeeping->taskCreated($task);

            return $task;
        });

        // After the outermost commit: an AI write runs inside the guard's
        // transaction, and staff must never hear of a task that rolls back.
        DB::afterCommit(fn () => $this->notifications->taskCreated($task, createdByAi: $createdByAi));

        return $task;
    }

    /**
     * @param  array<string, mixed>  $validated  without hotel_id and guest_id
     * @return array{task: Task, room_ready_to_return: bool}
     *
     * @throws DomainRuleException
     */
    public function update(Task $task, array $validated): array
    {
        $closes = in_array($validated['status'] ?? null, [TaskStatus::COMPLETED->value, TaskStatus::CANCELLED->value], true);

        $this->guardCancellationRequest($task, $closes);

        $invalidRelation = invalidRelation($task->hotel, [
            'rooms' => $validated['room_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
            'teams' => $validated['assigned_to_team_id'] ?? null,
            'users' => $validated['assigned_to_user_id'] ?? null,
            'taskCategories' => $validated['task_category_id'] ?? null,
            'stays' => $validated['stay_id'] ?? null,
        ]) ?? invalidRelation($task->hotel, [
            'users' => $validated['created_by_user_id'] ?? null,
        ]);

        if ($invalidRelation) {
            throw new DomainRuleException("The selected {$invalidRelation} does not belong to you.", 403);
        }

        if (array_key_exists('task_category_id', $validated) && ! array_key_exists('assigned_to_team_id', $validated) && ! $task->assigned_to_team_id) {
            $this->teamFromCategory($validated);
        }

        $teamId = $validated['assigned_to_team_id'] ?? $task->assigned_to_team_id;
        $taskCategoryId = $validated['task_category_id'] ?? $task->task_category_id;

        if (! $this->taskCategoryBelongsToTeam($teamId, $taskCategoryId)) {
            throw new DomainRuleException('The selected task category does not belong to the chosen team.', 403);
        }

        $this->applyStay($validated, $task);

        if (array_key_exists('reservation_id', $validated)) {
            $validated['guest_id'] = $this->guestIdForReservation($validated['reservation_id']);
        }

        $before = $task->getAttributes();

        try {
            DB::transaction(function () use ($task, $validated, $before): void {
                $this->housekeeping->lockRooms([$before['room_id'] ?? null, $validated['room_id'] ?? null]);
                $task->update($validated);
                $this->housekeeping->taskChanged($task, $before);
            });
        } catch (QueryException $e) {
            // Reopening a guest's escalation or room-change request while the
            // guest already has another one open (SPEC-007 partial unique
            // indexes): a conflict to report, not a server error.
            if ($message = $this->openRequestConflict($e)) {
                throw new DomainRuleException($message, 422);
            }

            throw $e;
        }

        DB::afterCommit(fn () => $this->notifications->taskReassigned($task, $before));

        // A finished repair does not reopen its room; it tells staff the room
        // can be returned to service (FR-019).
        return ['task' => $task, 'room_ready_to_return' => $this->maintenance->roomReadyToReturn($task)];
    }

    /**
     * A guest's open request to cancel a booking is answered only through
     * approve or decline, which record the outcome the guest is told. Closing
     * or deleting it as a plain task would leave the guest with no answer and
     * let them open a second request. It can still be reassigned.
     *
     * @throws DomainRuleException
     */
    public function guardCancellationRequest(Task $task, bool $closes): void
    {
        if (! $closes || ! $task->isCancellationRequest() || ! $task->isOpen()) {
            return;
        }

        throw new DomainRuleException('Answer a cancellation request by approving or declining it on its booking.', 422);
    }

    /**
     * The message for a write that would leave a guest with two open requests
     * of a kind they may only have one of, or null for any other error.
     */
    private function openRequestConflict(QueryException $e): ?string
    {
        if ($e->getCode() !== '23505') {
            return null;
        }

        return match (true) {
            str_contains($e->getMessage(), 'tasks_one_open_escalation_per_guest') => 'This guest already has another open escalation. Close it, or add to it, before reopening this one.',
            str_contains($e->getMessage(), 'tasks_one_open_room_change_per_stay') => 'This stay already has another open room-change request. Close it before reopening this one.',
            default => null,
        };
    }

    /**
     * A task linked to a stay (FR-018) is about that stay's room and
     * reservation: a room or reservation that says otherwise is rejected, and
     * missing ones are filled in from the stay. Checked against what the
     * task will hold after this request.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws DomainRuleException
     */
    private function applyStay(array &$validated, ?Task $task = null): void
    {
        $stayId = array_key_exists('stay_id', $validated) ? $validated['stay_id'] : $task?->stay_id;

        if (! $stayId) {
            return;
        }

        $stay = Stay::find($stayId);

        // A soft-deleted stay (its reservation was deleted) leaves stay_id
        // behind, since the FK's "set null" only fires on a hard delete.
        if (! $stay) {
            return;
        }

        foreach (['room_id', 'reservation_id'] as $field) {
            $value = array_key_exists($field, $validated) ? $validated[$field] : $task?->{$field};

            if ($value !== null && $stay->{$field} !== null && $value !== $stay->{$field}) {
                throw new DomainRuleException("The task's {$field} does not match its stay.", 422);
            }

            if ($value === null) {
                $validated[$field] = $stay->{$field};
            }
        }
    }

    /**
     * The guest a task is about is never chosen by the client — it's
     * always resolved from the reservation the task references.
     */
    private function guestIdForReservation(?string $reservationId): ?string
    {
        if (! $reservationId) {
            return null;
        }

        return Reservation::find($reservationId)?->guest_id;
    }

    /**
     * A category with no team chosen goes to the category's team, so a
     * maintenance task reaches the Maintenance team and a housekeeping task
     * the Housekeeping team without the client picking both (FR-015).
     *
     * @param  array<string, mixed>  $validated
     */
    private function teamFromCategory(array &$validated): void
    {
        if (empty($validated['task_category_id']) || ! empty($validated['assigned_to_team_id'])) {
            return;
        }

        $teamId = TaskCategory::whereKey($validated['task_category_id'])->value('team_id');

        if ($teamId) {
            $validated['assigned_to_team_id'] = $teamId;
        }
    }

    private function taskCategoryBelongsToTeam(?string $teamId, ?string $taskCategoryId): bool
    {
        if (! $taskCategoryId) {
            return true;
        }

        return TaskCategory::where('id', $taskCategoryId)
            ->where('team_id', $teamId)
            ->exists();
    }
}
