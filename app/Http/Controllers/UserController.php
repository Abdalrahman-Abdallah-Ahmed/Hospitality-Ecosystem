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

        if (UserAdministration::refusesSuperAdminGrant($request->validated('role'))) {
            return apiResponse(UserAdministration::SUPER_ADMIN_REFUSAL, 403);
        }

        if ($this->refusesAdminGrant($request, null)) {
            return apiResponse(self::ADMIN_GRANT_REFUSAL, 403);
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

        if ($error = UserAdministration::staffRoleError($validated['staff_role_id'] ?? null, $hotel, $role)) {
            return apiResponse($error['message'], $error['status']);
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

        if (UserAdministration::refusesSuperAdminGrant($request->validated('role'))) {
            return apiResponse(UserAdministration::SUPER_ADMIN_REFUSAL, 403);
        }

        if ($this->refusesAdminGrant($request, $user)) {
            return apiResponse(self::ADMIN_GRANT_REFUSAL, 403);
        }

        $changesRole = $request->safe()->has('role') && $request->validated('role') !== $user->role->value;

        if ($changesRole && $user->isOwner() && ! $request->user()->isSuperAdmin()) {
            return apiResponse('An account owner\'s role can only be changed by a super admin.', 403);
        }

        // A super admin must never know another user's password. Their own is fine.
        if ($request->user()->isSuperAdmin() && $user->isNot($request->user()) && $request->safe()->has('password')) {
            return apiResponse('A super admin cannot set another user\'s password. Send a password email instead.', 403);
        }

        if ($user->isSuperAdmin()) {
            // Super admins have no hotel or account links.
            if (array_filter($request->safe()->only(self::ACCOUNT_ATTRIBUTES)) !== []) {
                return apiResponse('A super admin cannot be linked to a hotel or account.', 403);
            }

            // Nothing in the API can grant the role back, so a demotion could
            // not be undone, and the last one would lock out the platform.
            if ($request->safe()->has('role')) {
                return apiResponse('A super admin\'s role cannot be changed through the API.', 403);
            }
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

        // accessibleHotelIds() ignores role, so a role left in place would keep
        // account-wide reach. Only a super admin gets here for an owner.
        $demotesOwner = $changesRole && $user->group_role !== null;

        if ($demotesOwner) {
            $validated = [...$validated, 'group_role' => null, 'hotel_group_id' => null];
        }

        $previousStaffRoleId = $user->staff_role_id;
        $previousAccess = $user->only(['role', 'group_role', 'hotel_group_id']);

        $user->update($validated);

        $this->recordStaffRoleAssignment($user, from: $previousStaffRoleId);

        // Account-wide access was removed, which /api/superadmin/users/{id}/access
        // audits as a platform event. The same change made here is too.
        if ($demotesOwner) {
            EventLogger::recordPlatform('platform.user.access_changed', $user, collect($previousAccess)
                ->map(fn ($from, string $key) => ['from' => EventLogger::normalize($from), 'to' => EventLogger::normalize($user->getAttribute($key))])
                ->all());
        }

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
     * someone other than the account owner or a super admin. Otherwise an admin
     * could promote an employee, sign in as them, and gain any hotel they are a
     * member of.
     */
    private function refusesAdminGrant(FormRequest $request, ?User $target): bool
    {
        $caller = $request->user();

        return $request->validated('role') === UserRole::ADMIN->value
            && ! $target?->isAdmin()
            && ! $caller->isOwner()
            && ! $caller->isSuperAdmin();
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
