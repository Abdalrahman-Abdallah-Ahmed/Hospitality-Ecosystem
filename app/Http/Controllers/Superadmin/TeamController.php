<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Resources\TeamResource;
use App\Models\Hotel;
use App\Models\Team;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * One hotel's teams, for assigning one through the user access endpoint
 * (spec 028). Super admin only, by the route file's middleware. The query names
 * the hotel itself, so a list never mixes hotels and doesn't depend on the
 * tenant scope.
 */
class TeamController extends Controller
{
    public function index(GenericIndexRequest $request, Hotel $hotel): JsonResponse
    {
        $query = Team::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->with('hotel');

        return apiResponse('Teams fetched successfully.', 200, TeamResource::collection(GenericQuery::apply($query, $request)));
    }
}
