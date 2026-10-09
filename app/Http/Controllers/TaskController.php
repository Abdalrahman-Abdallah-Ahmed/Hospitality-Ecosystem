<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\TaskCategoryResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\HousekeepingService;
use App\Services\Tasks\TaskCommands;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
     * Store a newly created resource in storage. The rules live in
     * TaskCommands, shared with the Admin AI; a refusal is rendered with the
     * status this endpoint always returned.
     */
    public function store(GenericStoreRequest $request, TaskCommands $tasks): JsonResponse
    {
        $this->authorize('create', Task::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id', 'guest_id']);
        $validated['created_by_user_id'] = $validated['created_by_user_id'] ?? $request->user()->id;

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $task = $tasks->create($hotel, $validated);

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
    public function update(GenericUpdateRequest $request, Task $task, TaskCommands $tasks): JsonResponse
    {
        $this->authorize('update', $task);

        $result = $tasks->update($task, unsetAttributes($request->validated(), ['hotel_id', 'guest_id']));

        $body = TaskResource::make($result['task']->load(['hotel', 'guest', 'room']))->resolve();

        // A finished repair does not reopen its room; it tells staff the room
        // can be returned to service (FR-019).
        if ($result['room_ready_to_return']) {
            $body['room_ready_to_return'] = true;
        }

        return apiResponse('Task updated successfully.', 200, $body);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task, HousekeepingService $housekeeping, TaskCommands $tasks)
    {
        $this->authorize('delete', $task);

        $tasks->guardCancellationRequest($task, true);

        DB::transaction(function () use ($task, $housekeeping): void {
            $housekeeping->taskRemoved($task);
            $task->delete();
        });

        return apiResponse('Task deleted successfully.', 200);
    }
}
