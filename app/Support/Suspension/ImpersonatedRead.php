<?php

namespace App\Support\Suspension;

use App\Support\Impersonation\CurrentImpersonation;
use Illuminate\Http\Request;

/**
 * Support reading a suspended hotel through an impersonation session. A read
 * keeps the user's whole scope. A write follows the normal suspension rules, so
 * a session can only write to active hotels (spec 027 FR-026, FR-027).
 */
class ImpersonatedRead
{
    public static function allows(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        return CurrentImpersonation::token() !== null;
    }
}
