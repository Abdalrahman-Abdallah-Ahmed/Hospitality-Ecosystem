<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\StaffRoleRequest;
use App\Http\Resources\StaffRoleResource;
use App\Models\StaffRole;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class StaffRoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StaffRole::class);

        $staffRoles = GenericQuery::apply(StaffRole::withCount('users'), $request);

        return apiResponse('Staff roles fetched successfully.', 200, StaffRoleResource::collection($staffRoles));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StaffRoleRequest $request): JsonResponse
    {
        $this->authorize('create', StaffRole::class);

        $hotel = resolveHotel($request->user(), $request->validated('hotel_id'));
        if (! $hotel) {
            return apiResponse('You must belong to, or specify, a valid hotel.', 403);
        }

        $staffRole = StaffRole::create([
            ...unsetAttributes($request->validated(), ['hotel_id']),
            'hotel_id' => $hotel->id,
        ]);

        return apiResponse('Staff role created successfully.', 201, StaffRoleResource::make($staffRole->loadCount('users')));
    }

    /**
     * Display the specified resource.
     */
    public function show(StaffRole $staffRole): JsonResponse
    {
        $this->authorize('view', $staffRole);

        return apiResponse('Staff role fetched successfully.', 200, StaffRoleResource::make($staffRole->loadCount('users')));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StaffRoleRequest $request, StaffRole $staffRole): JsonResponse
    {
        $this->authorize('update', $staffRole);

        $staffRole->update(unsetAttributes($request->validated(), ['hotel_id']));

        return apiResponse('Staff role updated successfully.', 200, StaffRoleResource::make($staffRole->loadCount('users')));
    }

    /**
     * Remove the specified resource from storage.
     *
     * A role still held by employees can't be deleted: they would silently
     * lose every permission (see User::permissions()). Reassign them first.
     */
    public function destroy(StaffRole $staffRole): JsonResponse
    {
        $this->authorize('delete', $staffRole);

        if ($staffRole->users()->exists()) {
            return apiResponse('This staff role is still assigned to employees. Reassign them before deleting it.', 422);
        }

        $staffRole->delete();

        return apiResponse('Staff role deleted successfully.', 200);
    }
}
