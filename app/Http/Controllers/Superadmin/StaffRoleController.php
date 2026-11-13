<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Resources\StaffRoleResource;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * One hotel's staff roles, for assigning one through the user access endpoint
 * (spec 028). Super admin only, by the route file's middleware. Read-only:
 * only a hotel's own admins define roles. The query names the hotel itself, so
 * a list never mixes hotels and doesn't depend on the tenant scope.
 */
class StaffRoleController extends Controller
{
    public function index(GenericIndexRequest $request, Hotel $hotel): JsonResponse
    {
        $query = StaffRole::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->withCount('users');

        return apiResponse('Staff roles fetched successfully.', 200, StaffRoleResource::collection(GenericQuery::apply($query, $request)));
    }
}
