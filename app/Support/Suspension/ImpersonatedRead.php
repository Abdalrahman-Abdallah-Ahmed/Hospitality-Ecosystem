<?php

namespace App\Support\Suspension;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Support reading a suspended hotel through an impersonation session, which
 * stage 5 adds. Until then the token has no impersonator_id, so this is false
 * for every request. A write follows the normal suspension rules.
 */
class ImpersonatedRead
{
    public static function allows(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->getAttribute('impersonator_id') !== null;
    }
}
