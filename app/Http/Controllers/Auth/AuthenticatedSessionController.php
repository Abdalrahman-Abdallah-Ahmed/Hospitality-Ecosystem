<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect('/');
    }

    public function apiLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return apiResponse('Invalid credentials.', 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return apiResponse('Authenticated successfully.', 200, [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'hotel'=> $user->hotel ?? null,
            ],
        ]);
    }

    public function apiLogout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return apiResponse('Logged out successfully.');
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function whatsappVerify(Request $request)
    {
        if ($request->query('hub.mode') === 'subscribe'
            && $request->query('hub.verify_token') === config('services.whatsapp.verify_token')) {
            return response($request->query('hub.challenge'), 200);
        }
        return response('Invalid verify token', 403);
    }
}
