<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Superadmin\UserAdministration;
use Illuminate\Http\JsonResponse;

/**
 * Set a new password from an emailed link: a new user's first link, or a reset
 * link a super admin sent. Public, because the person holding the link can't
 * log in yet.
 */
class PasswordResetController extends Controller
{
    public function store(ResetPasswordRequest $request, UserAdministration $users): JsonResponse
    {
        $credentials = $request->safe()->only(['token', 'email', 'password']);

        if (! $users->completeReset($credentials)) {
            return apiResponse('This password reset link is invalid or has expired.', 422);
        }

        return apiResponse('Password set successfully.');
    }
}
