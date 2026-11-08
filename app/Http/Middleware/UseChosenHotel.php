<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin's request works in one hotel: the one named by X-Hotel-Id, or their
 * default. Both the tenant scope and $user->hotel move to it, so lists, reports,
 * policies, new records and AI tools all agree (spec 025, research R3).
 *
 * Runs after EnsureNotSuspended, so a suspended hotel of the user's has already
 * been answered, and anything else the header names here is out of their reach.
 * Before route-model binding, so a record in another hotel is simply not found.
 *
 * Employees keep their whole scope and their home hotel: their staff role is
 * read through the scope, and belongs to the home hotel (spec 024 V3).
 */
class UseChosenHotel
{
    public function handle(Request $request, Closure $next): Response
    {
        $hotelIds = TenantContext::hotelIds();

        // A super admin's null scope.
        if ($hotelIds === null) {
            return $next($request);
        }

        $requested = $request->header('X-Hotel-Id');

        // The same answer whether the hotel exists or not, so it reveals nothing.
        if ($requested && ! in_array($requested, $hotelIds, true)) {
            return apiResponse('This action is unauthorized.', 403);
        }

        $user = $request->user();
        $currentId = TenantContext::currentHotelId();

        if ($currentId === null || ! $user->isAdmin()) {
            return $next($request);
        }

        // Only the in-memory relation: the stored home hotel stays. The team
        // belongs to the home hotel, so it is read while that is still in scope.
        if ($currentId !== $user->hotel_id) {
            $user->loadMissing(array_keys(array_filter(['team' => $user->team_id])));
            $user->setRelation('hotel', Hotel::find($currentId));
        }

        TenantContext::setHotelIds([$currentId]);

        return $next($request);
    }
}
