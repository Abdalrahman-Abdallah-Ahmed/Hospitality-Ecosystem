<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a route group while a platform feature is off (`feature:{name}`,
 * see App\Support\Features).
 *
 * It answers 404 rather than 403 so a switched-off feature looks absent, and
 * a client can tell it apart from a missing permission. It runs before
 * route-model binding and the policy, so no role or permission can reach a
 * feature that is off (spec 019).
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Features::enabled($feature)) {
            return apiResponse("The {$feature} feature is not enabled.", 404);
        }

        return $next($request);
    }
}
