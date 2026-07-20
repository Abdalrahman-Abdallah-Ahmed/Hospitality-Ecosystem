<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = getenv('API_KEY') ?: env('API_KEY');

        if (empty($expectedKey)) {
            return $next($request);
        }

        $providedKey = $request->header('X-API-KEY') ?? $request->query('api_key');

        if ($providedKey !== $expectedKey) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        return $next($request);
    }
}
