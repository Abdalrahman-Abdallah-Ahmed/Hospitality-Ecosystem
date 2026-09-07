<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to super admins.
 *
 * The role check itself already existed on User and in the policies; what was
 * missing was a way to put a whole route group behind it. The admin endpoints
 * report across every account, so a per-model policy is the wrong shape —
 * there is no single model to authorise against.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return apiResponse('This action is unauthorized.', 403);
        }

        return $next($request);
    }
}
