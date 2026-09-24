<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\TaskCategoryResource;
use App\Http\Resources\TaskResource;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\CreationNotificationService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Task::class);

        $query = Task::with(['hotel', 'guest', 'room']);

        $tasks = GenericQuery::apply($query, $request);

        $data = TaskResource::collection($tasks)->additional([
            'task_categories' => TaskCategoryResource::collection(TaskCategory::all()),
        ]);

        return apiResponse('Tasks fetched successfully.', 200, $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request, CreationNotificationService $notifications): JsonResponse
    {
        $this->authorize('create', Task::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id', 'guest_id']);
        $validated['created_by_user_id'] = $validated['created_by_user_id'] ?? $request->user()->id;

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

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
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $teamId = $validated['assigned_to_team_id'] ?? null;
        $taskCategoryId = $validated['task_category_id'] ?? null;

        if (! $this->taskCategoryBelongsToTeam($teamId, $taskCategoryId)) {
            return apiResponse('The selected task category does not belong to the chosen team.', 403);
        }

        if ($error = $this->applyStay($validated)) {
            return $error;
        }

        $task = Task::create([
            ...$validated,
            'hotel_id' => $hotel->id,
            'guest_id' => $this->guestIdForReservation($validated['reservation_id'] ?? null),
        ]);

        $notifications->taskCreated($task, createdByAi: false);

        return apiResponse('Task created successfully.', 201, TaskResource::make($task->load(['hotel', 'guest', 'room'])));
    }

    /**
     * Display the specified resource.
     */
    public function show(Task $task)
    {
        $this->authorize('view', $task);

        $task->load(['hotel', 'guest', 'room']);

        return apiResponse('Task fetched successfully.', 200, TaskResource::make($task));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = unsetAttributes($request->validated(), ['hotel_id', 'guest_id']);

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
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $teamId = $validated['assigned_to_team_id'] ?? $task->assigned_to_team_id;
        $taskCategoryId = $validated['task_category_id'] ?? $task->task_category_id;

        if (! $this->taskCategoryBelongsToTeam($teamId, $taskCategoryId)) {
            return apiResponse('The selected task category does not belong to the chosen team.', 403);
        }

        if ($error = $this->applyStay($validated, $task)) {
            return $error;
        }

        if (array_key_exists('reservation_id', $validated)) {
            $validated['guest_id'] = $this->guestIdForReservation($validated['reservation_id']);
        }

        $task->update($validated);

        return apiResponse('Task updated successfully.', 200, TaskResource::make($task->load(['hotel', 'guest', 'room'])));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);
        $task->delete();

        return apiResponse('Task deleted successfully.', 200);
    }

    /**
     * A task linked to a stay (FR-018) is about that stay's room and
     * reservation: a room or reservation that says otherwise is rejected, and
     * missing ones are filled in from the stay. Checked against what the
     * task will hold after this request.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyStay(array &$validated, ?Task $task = null): ?JsonResponse
    {
        $stayId = array_key_exists('stay_id', $validated) ? $validated['stay_id'] : $task?->stay_id;

        if (! $stayId) {
            return null;
        }

        $stay = Stay::find($stayId);

        // A soft-deleted stay (its reservation was deleted) leaves stay_id
        // behind, since the FK's "set null" only fires on a hard delete.
        if (! $stay) {
            return null;
        }

        foreach (['room_id', 'reservation_id'] as $field) {
            $value = array_key_exists($field, $validated) ? $validated[$field] : $task?->{$field};

            if ($value !== null && $stay->{$field} !== null && $value !== $stay->{$field}) {
                return apiResponse("The task's {$field} does not match its stay.", 422);
            }

            if ($value === null) {
                $validated[$field] = $stay->{$field};
            }
        }

        return null;
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
