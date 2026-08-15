<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Meta's X-Hub-Signature-256 header on inbound WhatsApp webhook
 * calls, since these routes sit outside auth:sanctum/api.key (Meta can't
 * send either) — this HMAC check is the actual security boundary.
 */
class VerifyWhatsAppWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            abort(403, 'Missing WhatsApp webhook signature.');
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $request->getContent(),
            (string) config('services.whatsapp.app_secret')
        );

        if (! hash_equals($expected, $signature)) {
            abort(403, 'Invalid WhatsApp webhook signature.');
        }

        return $next($request);
    }
}
