<?php

namespace App\Http\Controllers;
use App\Models\TaskCategory;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class TaskCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', TaskCategory::class);

        $taskCategories = GenericQuery::apply(
            TaskCategory::with(['hotel', 'team'])
                ->where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Task categories fetched successfully.', 200, $taskCategories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', TaskCategory::class);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'teams' => $validated['team_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $taskCategory = TaskCategory::create([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Task category created successfully.', 201, $taskCategory->load(['hotel', 'team']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, TaskCategory $taskCategory): JsonResponse
    {
        $this->authorize('update', $taskCategory);

        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $invalidRelation = invalidRelation($hotel, [
            'teams' => $validated['team_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        $taskCategory->update([...$validated, 'hotel_id' => $hotel->id]);

        return apiResponse('Task category updated successfully.', 200, $taskCategory->load(['hotel', 'team']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaskCategory $taskCategory)
    {
        $this->authorize('delete', $taskCategory);
        $taskCategory->delete();
        return apiResponse('Task category deleted successfully.', 200);
    }
}
