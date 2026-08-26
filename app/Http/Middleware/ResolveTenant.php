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

        return $next($request);
    }
}
