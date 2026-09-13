<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Hotel;
use App\Models\User;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Columns that decide which hotels a user can reach. Only a super admin
     * may write them: an admin who could would join themselves to another
     * account and read its data through the tenant scope.
     */
    private const ACCOUNT_ATTRIBUTES = ['hotel_group_id', 'group_role'];

    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::with(['hotel', 'team']);

        if (! $request->user()->isSuperAdmin()) {
            $hotelId = $request->user()->hotel?->id;

            $query->where(function ($inner) use ($hotelId, $request) {
                $inner->where('id', $request->user()->id);

                if ($hotelId) {
                    $inner->orWhere('hotel_id', $hotelId);
                }
            });
        }

        $users = GenericQuery::apply($query, $request);

        return apiResponse('Users fetched successfully.', 200, UserResource::collection($users));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        if ($denied = $this->denySuperAdminGrant($request)) {
            return $denied;
        }

        $validated = $this->assignableAttributes($request);

        if ($request->user()->isSuperAdmin()) {
            $hotel = ($id = $request->validated()['hotel_id'] ?? null) ? Hotel::find($id) : null;
        } else {
            $hotel = $request->user()->hotel;

            if (! $hotel) {
                return apiResponse('User does not have an associated hotel.', 403);
            }
        }

        $teamId = $validated['team_id'] ?? null;

        if ($teamId && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $user = User::create([...$validated, 'hotel_id' => $hotel?->id]);

        return apiResponse('User created successfully.', 201, UserResource::make($user->load(['hotel', 'team'])));
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return apiResponse('User fetched successfully.', 200, UserResource::make($user->load(['hotel', 'team'])));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        if ($denied = $this->denySuperAdminGrant($request)) {
            return $denied;
        }

        $validated = $this->assignableAttributes($request);

        $hotel = $user->hotel_id ? Hotel::find($user->hotel_id) : null;
        $teamId = $validated['team_id'] ?? $user->team_id;

        if ($teamId && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $user->update($validated);

        return apiResponse('User updated successfully.', 200, UserResource::make($user->load(['hotel', 'team'])));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return apiResponse('User deleted successfully.', 200);
    }

    /**
     * Only a super admin can create or promote a super admin. The rule turns
     * on who is asking rather than on the value, so the schema-derived
     * validation rules cannot express it.
     */
    private function denySuperAdminGrant(FormRequest $request): ?JsonResponse
    {
        if ($request->user()->isSuperAdmin() || $request->validated('role') !== UserRole::SUPER_ADMIN->value) {
            return null;
        }

        return apiResponse('Only a super admin can assign the super admin role.', 403);
    }

    /**
     * hotel_id is never written from the payload — store() resolves it and
     * update() keeps the current one. Account membership is dropped the same
     * way for anyone but a super admin.
     */
    private function assignableAttributes(FormRequest $request): array
    {
        $ignored = $request->user()->isSuperAdmin()
            ? ['hotel_id']
            : ['hotel_id', ...self::ACCOUNT_ATTRIBUTES];

        return unsetAttributes($request->validated(), $ignored);
    }
}
