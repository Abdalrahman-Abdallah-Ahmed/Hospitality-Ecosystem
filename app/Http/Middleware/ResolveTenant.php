<?php

namespace App\Http\Middleware;

use App\Support\Observability\TagsSentryScope;
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

            // Which hotel and which endpoint, for an error that follows (spec 017 FR-027).
            TagsSentryScope::tag([
                'hotel_id' => TenantContext::currentHotelId(),
                'operation' => $request->route()?->getName() ?? $request->method().' '.$request->route()?->uri(),
            ]);
        }

        try {
            return $next($request);
        } finally {
            // TenantContext is static, so it outlives the request on a process
            // that serves several (Octane, the test suite). Clear it, even on
            // an exception, so the next request starts without this tenant.
            TenantContext::reset();
        }
    }
}
