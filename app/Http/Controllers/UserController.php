<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Superadmin\UserAdministration;
use App\Support\Audit\EventLogger;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Columns that decide which hotels a user can reach. Never written here,
     * for any caller: an admin who could would join themselves to another
     * account, and these have rules that live in
     * App\Services\Superadmin\UserAdministration.
     */
    private const ACCOUNT_ATTRIBUTES = ['hotel_group_id', 'group_role'];

    private const ADMIN_GRANT_REFUSAL = 'Only the account owner can create or promote an admin.';

    private const RELATIONS = ['hotel', 'team', 'staffRole'];

    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $hotelId = $request->user()->hotel?->id;

        $query = User::with(self::RELATIONS)->where(function ($inner) use ($hotelId, $request) {
            $inner->where('id', $request->user()->id);

            if ($hotelId) {
                $inner->orWhere('hotel_id', $hotelId);
            }
        });

        $users = GenericQuery::apply($query, $request);

        return apiResponse('Users fetched successfully.', 200, UserResource::collection($users));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        if (UserAdministration::refusesSuperAdminGrant($request->validated('role'))) {
            return apiResponse(UserAdministration::SUPER_ADMIN_REFUSAL, 403);
        }

        if ($this->refusesAdminGrant($request, null)) {
            return apiResponse(self::ADMIN_GRANT_REFUSAL, 403);
        }

        $validated = $this->assignableAttributes($request);

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('User does not have an associated hotel.', 403);
        }

        $teamId = $validated['team_id'] ?? null;

        if ($teamId && invalidRelation($hotel, ['teams' => $teamId])) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $role = $validated['role'] ?? UserRole::EMPLOYEE->value;

        if ($error = UserAdministration::staffRoleError($validated['staff_role_id'] ?? null, $hotel, $role)) {
            return apiResponse($error['message'], $error['status']);
        }

        $user = User::create([...$validated, 'hotel_id' => $hotel->id]);

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

        if (UserAdministration::refusesSuperAdminGrant($request->validated('role'))) {
            return apiResponse(UserAdministration::SUPER_ADMIN_REFUSAL, 403);
        }

        if ($this->refusesAdminGrant($request, $user)) {
            return apiResponse(self::ADMIN_GRANT_REFUSAL, 403);
        }

        // An owner's role changes only through /api/superadmin, where the
        // account-wide access that goes with it is handled and audited.
        $changesRole = $request->safe()->has('role') && $request->validated('role') !== $user->role->value;

        if ($changesRole && $user->isOwner()) {
            return apiResponse('An account owner\'s role can only be changed by a super admin.', 403);
        }

        $validated = $this->assignableAttributes($request);

        $hotel = $user->hotel_id ? Hotel::find($user->hotel_id) : null;
        $teamId = $validated['team_id'] ?? $user->team_id;

        if ($teamId && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            return apiResponse('The selected team does not belong to you.', 403);
        }

        $role = $validated['role'] ?? $user->role->value;

        if ($error = UserAdministration::staffRoleError($validated['staff_role_id'] ?? null, $hotel, $role)) {
            return apiResponse($error['message'], $error['status']);
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
     * Whether this request would make someone an admin who isn't one, sent by
     * someone other than the account owner. Otherwise an admin
     * could promote an employee, sign in as them, and gain any hotel they are a
     * member of.
     */
    private function refusesAdminGrant(FormRequest $request, ?User $target): bool
    {
        $caller = $request->user();

        return $request->validated('role') === UserRole::ADMIN->value
            && ! $target?->isAdmin()
            && ! $caller->isOwner();
    }

    /**
     * hotel_id is never written from the payload — store() resolves it and
     * update() keeps the current one. Account membership is dropped the same
     * way, for every caller.
     */
    private function assignableAttributes(FormRequest $request): array
    {
        return unsetAttributes($request->validated(), ['hotel_id', ...self::ACCOUNT_ATTRIBUTES]);
    }
}
