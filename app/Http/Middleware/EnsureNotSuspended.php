<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\User;
use App\Support\Observability\TagsSentryScope;
use App\Support\Suspension\HotelAccess;
use App\Support\Suspension\ImpersonatedRead;
use App\Support\Suspension\SuspendedResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a suspended customer, and takes suspended hotels out of the request for
 * a user who has others. Runs after ResolveTenant and before route-model
 * binding, so a suspended hotel's record id is simply not found.
 *
 * It keeps suspension in one place: User::accessibleHotelIds() still returns
 * every hotel, and only this request's context is narrowed.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $hotelIds = TenantContext::hotelIds();

        // A super admin's null scope: they are never suspended.
        if ($hotelIds === null || ImpersonatedRead::allows($request)) {
            return $next($request);
        }

        $access = HotelAccess::for($hotelIds, $request->user());

        // A header naming a hotel the user can't reach isn't in $access, so
        // they learn nothing about it.
        if ($scope = $access->refusalFor($request->header('X-Hotel-Id')) ?? $access->refusal()) {
            return SuspendedResponse::make($scope);
        }

        if ($access->anyRemoved()) {
            $this->narrow($request->user(), $access);
        }

        return $next($request);
    }

    private function narrow(User $user, HotelAccess $access): void
    {
        $defaultRemoved = $user->hotel_id !== null && $access->removed->has($user->hotel_id);

        // Both are hotel-scoped and belong to the default hotel, so they have to
        // be read while that hotel is still in scope. Otherwise an employee
        // assigned to another hotel would lose every permission there, and
        // GET /api/user would disagree with login about their team.
        if ($defaultRemoved) {
            $user->loadMissing(array_keys(array_filter(['staffRole' => $user->staff_role_id, 'team' => $user->team_id])));
        }

        TenantContext::setHotelIds($access->activeIds);

        $currentId = TenantContext::currentHotelId();

        if ($currentId === null || $access->removed->has($currentId)) {
            $currentId = $access->activeIds[0];
            TenantContext::setCurrentHotelId($currentId);
            TagsSentryScope::tag(['hotel_id' => $currentId]);
        }

        // Policies and resolveHotel() read $user->hotel. Only the in-memory
        // relation changes: the attribute would be persisted by a later save.
        if ($defaultRemoved) {
            $user->setRelation('hotel', Hotel::find($currentId));
        }
    }
}
