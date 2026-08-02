<?php

namespace App\Http\Controllers;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
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

        $tasks = GenericQuery::apply(
            Task::with(['hotel', 'guest', 'room'])
                ->where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Tasks fetched successfully.', 200, $tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'rooms' => $validated['room_id'] ?? null,
            'guests' => $validated['guest_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
            'teams' => $validated['assigned_to_team_id'] ?? null,
            'users' => $validated['assigned_to_user_id'] ?? null,
            'taskCategories' => $validated['task_category_id'] ?? null,
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

        $task = Task::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Task created successfully.', 201, $task->load(['hotel', 'guest', 'room']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Task $task)
    {
        $this->authorize('view', $task);

        $task->load(['hotel', 'guest', 'room']);
        return apiResponse('Task fetched successfully.', 200, $task);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'rooms' => $validated['room_id'] ?? null,
            'guests' => $validated['guest_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
            'teams' => $validated['assigned_to_team_id'] ?? null,
            'users' => $validated['assigned_to_user_id'] ?? null,
            'taskCategories' => $validated['task_category_id'] ?? null,
        ]) ?? invalidRelation($hotel, [
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

        $task->update([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Task updated successfully.', 200, $task->load(['hotel', 'guest', 'room']));
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
