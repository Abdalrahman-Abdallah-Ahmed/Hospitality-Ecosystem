<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Enums\Permission;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Who works at the hotel and what they may do, read-only (D17). Every value
 * is picked from an explicit allow-list: a model's toArray() would be one
 * new column away from sending a secret to the model provider (FR-020).
 * There is no write counterpart: users, roles and permissions are changed
 * only on the settings screens (FR-018).
 */
class GetStaffTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read-only: the hotel\'s staff and staff roles. Each user with name, email, role (admin or employee), '
            .'staff role, team and the permissions they effectively hold; each staff role with the permissions it '
            .'grants. Filter by staff role name or part of a user\'s name. You cannot change users, roles or '
            .'permissions; the admin does that on the settings screens.';
    }

    public function handle(Request $request): Stringable|string
    {
        $users = User::query()
            ->where('hotel_id', $this->hotel->id)
            ->with(['staffRole', 'team'])
            ->when($request->filled('role'), fn (Builder $query) => $query->whereHas('staffRole', fn (Builder $role) => $role->whereRaw('lower(name) = ?', [mb_strtolower($request->string('role')->toString())])))
            ->when($request->filled('search'), fn (Builder $query) => $query->where('name', 'ilike', '%'.addcslashes($request->string('search')->toString(), '%_\\').'%'))
            ->orderBy('name');

        $staff = ListResult::queryPayload($users, ListResult::limit($request), fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'staff_role' => $user->staffRole?->name,
            'team' => $user->team?->name,
            'permissions' => $user->isAdmin() || $user->isSuperAdmin()
                ? 'all'
                : array_map(fn (Permission $permission) => $permission->value, $user->permissions()),
        ]);

        $roles = StaffRole::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->orderBy('name')
            ->get()
            ->map(fn (StaffRole $role) => [
                'name' => $role->name,
                'permissions' => array_map(fn (Permission $permission) => $permission->value, $role->grantedPermissions()),
            ]);

        return json_encode([...$staff, 'staff_roles' => $roles->values()->all()], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->description('Only users with this staff role, by name.'),
            'search' => $schema->string()->description("Part of a user's name."),
            'limit' => $schema->integer()->description('At most this many users, 1-50. Default 50.'),
        ];
    }
}
