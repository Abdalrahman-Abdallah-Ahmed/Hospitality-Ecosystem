<?php

namespace App\Http\Controllers\Superadmin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\StoreUserRequest;
use App\Http\Requests\Superadmin\UpdateUserAccessRequest;
use App\Http\Requests\Superadmin\UpdateUserRequest;
use App\Http\Requests\Superadmin\UserIndexRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Superadmin\UserAdministration;
use App\Support\RequestRules\GenericQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Every user who is not a super admin, across the platform. The route guard is
 * the authorization: this calls no tenant policy.
 */
class UserController extends Controller
{
    private const RELATIONS = ['hotel', 'team', 'staffRole', 'hotels'];

    public function __construct(private readonly UserAdministration $users) {}

    public function index(UserIndexRequest $request): JsonResponse
    {
        $query = User::query()->where('role', '!=', UserRole::SUPER_ADMIN->value)->with(self::RELATIONS);

        // These two match a user attached in any of three ways. Left in the
        // filter, Filterable would add an exact WHERE on the column and drop
        // users attached only through a membership.
        $filter = (array) $request->input('filter', []);

        if (filled($filter['hotel_group_id'] ?? null)) {
            $query->attachedToAccount((array) $filter['hotel_group_id']);
        }

        if (filled($filter['hotel_id'] ?? null)) {
            $query->attachedToHotel((array) $filter['hotel_id']);
        }

        unset($filter['hotel_group_id'], $filter['hotel_id']);
        $request->merge(['filter' => $filter]);

        $users = TenantContext::withoutScope(fn () => GenericQuery::apply($query, $request));

        return apiResponse('Users fetched successfully.', 200, UserResource::collection($users));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        [$user, $emailSent] = $this->users->create($request->validated());

        return apiResponse('User created successfully.', 201, [
            ...$this->resource($user)->resolve(),
            'reset_email_sent' => $emailSent,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return apiResponse('User fetched successfully.', 200, $this->resource($this->administrable($user)));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($this->administrable($user), $request->validated());

        return apiResponse('User updated successfully.', 200, $this->resource($user));
    }

    public function updateAccess(UpdateUserAccessRequest $request, User $user): JsonResponse
    {
        $user = $this->users->changeAccess($this->administrable($user), $request->validated());

        return apiResponse('User access updated successfully.', 200, $this->resource($user));
    }

    public function sendPasswordReset(User $user): JsonResponse
    {
        if (! $this->users->sendPasswordReset($this->administrable($user))) {
            return apiResponse('The password email could not be sent. Please try again.', 503);
        }

        return apiResponse('Password email sent successfully.');
    }

    public function revokeTokens(User $user): JsonResponse
    {
        $this->users->revokeAccess($this->administrable($user));

        return apiResponse('User access revoked successfully.');
    }

    /**
     * Super admins are outside this administration: to these endpoints they
     * don't exist.
     */
    private function administrable(User $user): User
    {
        abort_if($user->isSuperAdmin(), 404);

        return $user;
    }

    private function resource(User $user): UserResource
    {
        return UserResource::make(TenantContext::withoutScope(fn () => $user->load(self::RELATIONS)));
    }
}
