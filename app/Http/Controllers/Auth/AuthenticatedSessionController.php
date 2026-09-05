<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
                'hotel' => $user->hotel ?? null,
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
        // PHP converts dots in query-string keys to underscores (hub.mode -> hub_mode)
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === config('services.whatsapp.verify_token')) {
            return response($request->query('hub_challenge'), 200);
        }

        return response('Invalid verify token', 403);
    }

    public function whatsappWebhook(Request $request)
    {
        $signature = $request->header('X-Hub-Signature-256', '');
        $appSecret = config('services.whatsapp.app_secret');

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $appSecret);

        if (! $appSecret || ! hash_equals($expected, $signature)) {
            Log::warning('WhatsApp webhook received with invalid signature.');

            return response('Invalid signature', 403);
        }

        Log::info('WhatsApp webhook payload received.', $request->all());

        return response('', 200);
    }
}
