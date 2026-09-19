<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Read through config, never env(): once `php artisan optimize` caches
        // the config, env() returns null and this check would pass everything.
        $expectedKey = (string) config('app.api_key');

        if ($expectedKey === '') {
            // Skipping the check is a local convenience only. Anywhere else an
            // unset key is a misconfiguration, and failing open would publish
            // every route this middleware guards.
            return app()->environment('local', 'testing')
                ? $next($request)
                : $this->invalidKey();
        }

        // Header only. A key in the query string ends up in access logs,
        // proxy logs and browser history.
        $providedKey = (string) $request->header('X-API-KEY');

        if (! hash_equals($expectedKey, $providedKey)) {
            return $this->invalidKey();
        }

        return $next($request);
    }

    private function invalidKey(): Response
    {
        return response()->json(['message' => 'Invalid API key.'], 401);
    }
}
