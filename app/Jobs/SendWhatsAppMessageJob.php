<?php

namespace App\Jobs;

use App\Services\WhatsAppMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a fixed WhatsApp reply off the webhook request, so a slow Graph API
 * call never delays the acknowledgement Meta is waiting for (a late ack
 * makes Meta redeliver the message). Sending is safe to retry: nothing
 * else happens here.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $phoneNumber,
        public string $text,
    ) {}

    public function handle(WhatsAppMessageService $whatsApp): void
    {
        $whatsApp->send($this->phoneNumber, $this->text);
    }
}
