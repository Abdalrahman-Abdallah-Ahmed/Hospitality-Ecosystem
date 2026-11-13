<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a super admin's own token on the hotel routes (spec 028). A super
 * admin works in /api/superadmin, and reaches a hotel only by impersonating one
 * of its users: that token belongs to the hotel user, so it passes here and the
 * user's own permissions apply. Runs before route-model binding, so the answer
 * does not depend on whether the addressed record exists.
 *
 * Why the default tenant guard isn't enough on its own:
 *
 * - Every user reaches only the hotels in User::accessibleHotelIds(), and every
 *   query and policy is limited to that list. That keeps a hotel's staff out of
 *   other hotels.
 * - A super admin's list is null, meaning "no limit", on purpose: the super admin
 *   area reads across every customer (dashboard, accounts, hotels, users, usage)
 *   with the same user and the same scope.
 * - So on the hotel routes the default guard would let a super admin into every
 *   hotel. Making their list empty instead ([]) would break the super admin area
 *   with silent zero-row results (design Alternative G).
 * - This middleware is the one exception: unrestricted inside /api/superadmin,
 *   an outsider on the hotel routes. POST /logout and GET /user opt out of it.
 */
class DenySuperAdmin
{
    public const REFUSAL = 'Super admins use /api/superadmin.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuperAdmin()) {
            return apiResponse(self::REFUSAL, 403);
        }

        return $next($request);
    }
}
