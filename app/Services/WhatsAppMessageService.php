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

    /**
     * Download an inbound media attachment (e.g. an image) from the Graph API.
     * Media is fetched in two steps: resolve the media ID to a short-lived
     * CDN URL, then download the bytes from that URL (both calls require
     * the same bearer token).
     *
     * @return array{content: string, mime_type: ?string}
     *
     * @throws RuntimeException if either Graph API call does not succeed.
     */
    public function downloadMedia(string $mediaId): array
    {
        $version = config('services.whatsapp.graph_api_version');
        $token = config('services.whatsapp.access_token');

        $metadata = Http::withToken($token)
            ->get("https://graph.facebook.com/{$version}/{$mediaId}");

        if ($metadata->failed()) {
            throw new RuntimeException(
                "Failed to fetch WhatsApp media metadata for [{$mediaId}]: ".$metadata->body()
            );
        }

        $url = $metadata->json('url');

        $file = Http::withToken($token)->get($url);

        if ($file->failed()) {
            throw new RuntimeException("Failed to download WhatsApp media [{$mediaId}].");
        }

        return [
            'content' => $file->body(),
            'mime_type' => $metadata->json('mime_type'),
        ];
    }
}
