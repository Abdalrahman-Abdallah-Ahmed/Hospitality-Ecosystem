<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated user's accessible hotels once per request and
 * populates TenantContext, which BelongsToHotel's global scope reads on
 * every tenant-owned model query. Must run after auth:sanctum.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $hotelIds = $user->accessibleHotelIds();
            TenantContext::setHotelIds($hotelIds);

            // Optional header lets a multi-hotel user pick one property to work in.
            $requested = $request->header('X-Hotel-Id');
            $available = $hotelIds ?? ($user->hotel_id ? [$user->hotel_id] : []);

            TenantContext::setCurrentHotelId(
                $requested && in_array($requested, $available, true) ? $requested : ($user->hotel_id ?? $available[0] ?? null)
            );
        }

        try {
            return $next($request);
        } finally {
            // TenantContext is process-static and route-model binding runs
            // *before* this middleware. On a persistent worker (Octane) or
            // any process serving more than one request, leaving the context
            // set would mean the next request resolves its bindings under the
            // previous request's tenant -- making the same cross-hotel lookup
            // answer 403 or 404 purely on request order. Hand the process back
            // in the state we found it.
            TenantContext::reset();
        }
    }
}
