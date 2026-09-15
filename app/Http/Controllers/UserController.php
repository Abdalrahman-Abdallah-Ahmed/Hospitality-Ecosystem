<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Audit\EventLogger;
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

    private const RELATIONS = ['hotel', 'team', 'staffRole'];

    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::with(self::RELATIONS);

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

        $role = $validated['role'] ?? UserRole::EMPLOYEE->value;

        if ($denied = $this->denyStaffRole($validated, $hotel, $role)) {
            return $denied;
        }

        $user = User::create([...$validated, 'hotel_id' => $hotel?->id]);

        $this->recordStaffRoleAssignment($user, from: null);

        return apiResponse('User created successfully.', 201, UserResource::make($user->load(self::RELATIONS)));
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return apiResponse('User fetched successfully.', 200, UserResource::make($user->load(self::RELATIONS)));
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

        $role = $validated['role'] ?? $user->role->value;

        if ($denied = $this->denyStaffRole($validated, $hotel, $role)) {
            return $denied;
        }

        // A user who stops being an employee loses their staff role, rather
        // than keeping one that would silently apply again on a demotion.
        if ($role !== UserRole::EMPLOYEE->value) {
            $validated['staff_role_id'] = null;
        }

        $previousStaffRoleId = $user->staff_role_id;

        $user->update($validated);

        $this->recordStaffRoleAssignment($user, from: $previousStaffRoleId);

        return apiResponse('User updated successfully.', 200, UserResource::make($user->load(self::RELATIONS)));
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
     * A staff role can only be given to an employee — admins already hold
     * every permission — and must belong to the user's hotel. The schema rule
     * only checks the id exists somewhere.
     */
    private function denyStaffRole(array $validated, ?Hotel $hotel, string $role): ?JsonResponse
    {
        $staffRoleId = $validated['staff_role_id'] ?? null;

        if ($staffRoleId === null) {
            return null;
        }

        if ($role !== UserRole::EMPLOYEE->value) {
            return apiResponse('Only employees can be given a staff role.', 422);
        }

        if (! $hotel || invalidRelation($hotel, ['staffRoles' => $staffRoleId])) {
            return apiResponse('The selected staff role does not belong to you.', 403);
        }

        return null;
    }

    /**
     * User is not an audited model, so a change of staff role — a change of
     * what someone may do — is recorded here explicitly.
     */
    private function recordStaffRoleAssignment(User $user, ?string $from): void
    {
        if ($user->staff_role_id === $from) {
            return;
        }

        EventLogger::record($user, 'staff_role_assigned', [
            'staff_role_id' => ['from' => $from, 'to' => $user->staff_role_id],
        ]);
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
