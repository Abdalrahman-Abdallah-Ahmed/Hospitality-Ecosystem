<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\StaffRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PermissionController extends Controller
{
    /**
     * Every permission an admin can put in a staff role, grouped by resource,
     * plus what an employee without a role gets — everything a role editor
     * needs to render.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StaffRole::class);

        $groups = collect(Permission::cases())
            ->groupBy(fn (Permission $permission) => $permission->group())
            ->map(fn ($permissions, string $group) => [
                'group' => $group,
                'label' => Str::headline($group),
                'permissions' => $permissions->map(fn (Permission $permission) => [
                    'value' => $permission->value,
                    'action' => $permission->action(),
                    'label' => Str::headline($permission->action()),
                ])->values(),
            ])
            ->values();

        return apiResponse('Permissions fetched successfully.', 200, [
            'groups' => $groups,
            'employee_defaults' => array_map(
                fn (Permission $permission) => $permission->value,
                Permission::employeeDefaults(),
            ),
        ]);
    }
}
