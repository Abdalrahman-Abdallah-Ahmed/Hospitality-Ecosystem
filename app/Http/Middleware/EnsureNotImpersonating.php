<?php

namespace App\Http\Middleware;

use App\Support\Impersonation\CurrentImpersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses what could outlast a 30-minute impersonation session: issuing another
 * credential, changing users or staff roles, which covers passwords and emails,
 * and the AI advisor, whose conversation and pending confirmations belong to the
 * user (spec 027). Runs before route-model binding, so the answer does not
 * depend on whether the addressed record exists.
 */
class EnsureNotImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CurrentImpersonation::token()) {
            return apiResponse(CurrentImpersonation::REFUSAL, 403);
        }

        return $next($request);
    }
}
