<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\EndImpersonationRequest;
use App\Models\User;
use App\Services\Superadmin\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impersonation sessions (spec 027). The route guard is the authorization.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function start(Request $request, User $user): JsonResponse
    {
        $token = $this->impersonation->start($request->user(), $user);

        return apiResponse('Impersonation started successfully.', 200, [
            'token' => $token->plainTextToken,
            'token_id' => $token->accessToken->getKey(),
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
        ]);
    }

    public function end(EndImpersonationRequest $request): JsonResponse
    {
        if (! $this->impersonation->end((int) $request->validated('token_id'))) {
            return apiResponse('This is not a running impersonation session.', 404);
        }

        return apiResponse('Impersonation ended successfully.');
    }

    /**
     * Ends this user's sessions and nothing else, for a super admin who wasn't
     * given a session's token id. revoke-tokens also signs the user out and
     * unpairs their device. A super admin target doesn't exist here, as on the
     * other user administration routes.
     */
    public function endAllFor(User $user): JsonResponse
    {
        abort_if($user->isSuperAdmin(), 404);

        return apiResponse('Impersonation ended successfully.', 200, ['ended' => $this->impersonation->endAllFor($user)]);
    }
}
