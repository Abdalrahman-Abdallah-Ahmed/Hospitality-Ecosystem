<?php

namespace App\Support\Impersonation;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Whether this request is an impersonation session (spec 027): the one place
 * that decides it, so the audit trail, Sentry, the refusals and the suspension
 * read exception cannot disagree.
 *
 * A session is a token of the impersonated user with an impersonator_id. Its
 * name is for reading only. Nothing is held statically, so nothing outlives the
 * request on a process that serves several.
 */
class CurrentImpersonation
{
    public const REFUSAL = 'This action is not available while impersonating.';

    public static function token(): ?PersonalAccessToken
    {
        // Only a user already signed in. This runs on every User write, and
        // Auth::user() would try to authenticate as a side effect.
        $token = Auth::hasUser() ? Auth::user()->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken && $token->getAttribute('impersonator_id') !== null
            ? $token
            : null;
    }

    /**
     * Stops a write that could outlast the session, wherever it comes from. The
     * not_impersonating middleware gives the same answer earlier on the routes
     * it covers; this holds for any route or tool that does not have it.
     *
     * @throws DomainRuleException during a session
     */
    public static function refuseDuringSession(): void
    {
        if (self::token()) {
            throw new DomainRuleException(self::REFUSAL, 403);
        }
    }

    /**
     * What a client needs to show a banner, or null outside a session.
     *
     * @return array{impersonator: array{id: string, name: string}|null, expires_at: string}|null
     */
    public static function details(): ?array
    {
        $token = self::token();

        if (! $token) {
            return null;
        }

        return [
            'impersonator' => User::find($token->getAttribute('impersonator_id'))?->only(['id', 'name']),
            'expires_at' => $token->expires_at->toIso8601String(),
        ];
    }
}
