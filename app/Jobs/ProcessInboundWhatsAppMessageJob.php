<?php

namespace App\Jobs;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\SenderType;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\WhatsAppMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Files\Image;

/**
 * Generates and sends the AI reply for an inbound WhatsApp message. Sender
 * recognition and the device-pairing check already happened synchronously
 * in WhatsAppController::whatsappWebhook() (via its private identify()/
 * checkPaired() helpers) before this job was dispatched — this job only
 * does the slow part (the LLM call and the outbound send), which is why
 * it's queued rather than run inline in the webhook request.
 */
class ProcessInboundWhatsAppMessageJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $phoneNumber,
        public string $messageText,
        public SenderType $senderType,
        public User|Guest|null $sender,
        public ?Hotel $hotel,
        public ?Reservation $reservation,
        public bool $devicePaired,
        public ?string $imageMediaId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppMessageService $whatsApp): void
    {
        if ($this->senderType === SenderType::ADMIN && ! $this->devicePaired) {
            $whatsApp->send(
                $this->phoneNumber,
                'This number is not yet paired to your account. Please pair your WhatsApp device from the dashboard before using the advisor here.'
            );

            return;
        }

        if ($this->senderType === SenderType::UNKNOWN) {
            $whatsApp->send(
                $this->phoneNumber,
                "Sorry, we couldn't recognize this number. Please contact the hotel directly for assistance."
            );

            return;
        }

        $agent = $this->senderType === SenderType::ADMIN
            ? AdminAdvisorAgent::make(user: $this->sender)
            : GuestConciergeAgent::make(guest: $this->sender, hotel: $this->hotel, reservation: $this->reservation);

        $agent->continueLastConversation($this->sender);

        // Only the admin advisor knows what to do with a reservation
        // screenshot (it has the create-reservation tool); a guest sending
        // a photo just falls through to its caption text, if any.
        $attachments = [];
        $messageText = $this->messageText;

        if ($this->imageMediaId && $this->senderType === SenderType::ADMIN) {
            $media = $whatsApp->downloadMedia($this->imageMediaId);
            $attachments[] = Image::fromBase64(base64_encode($media['content']), $media['mime_type']);

            if ($messageText === '') {
                $messageText = 'Extract the reservation details from this screenshot and create the reservation.';
            }
        }

        $response = $agent->prompt($messageText, attachments: $attachments);

        $whatsApp->send($this->phoneNumber, $response->text);
    }
}
