<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends outbound WhatsApp messages via the Meta Cloud API, using this
 * platform's single shared WABA number (no per-hotel credentials).
 */
class WhatsAppMessageService
{
    /**
     * Send a plain text message to the given WhatsApp number.
     *
     * @throws RuntimeException if the Graph API call does not succeed.
     */
    public function send(string $to, string $text): void
    {
        $version = config('services.whatsapp.graph_api_version');
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        $response = Http::withToken(config('services.whatsapp.access_token'))
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $text],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Failed to send WhatsApp message to [{$to}]: ".$response->body()
            );
        }
    }
}
