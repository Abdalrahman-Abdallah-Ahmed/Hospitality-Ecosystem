<?php

namespace App\Jobs;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\MeterFeature;
use App\Enums\SenderType;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use App\Support\Audit\EventLogger;
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
    public function handle(WhatsAppMessageService $whatsApp, MeteringService $metering): void
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

        // The concierge/advisor agents write records through their tools
        // (reservations, service-request tasks, recommendation updates) — all
        // of it belongs to the AI actor, not a human.
        //
        // Wrapped in a cost context as well: this is the externally-driven
        // spend path, where anyone who can message the hotel's number can
        // cause a provider charge. Declaring the trigger here is what lets
        // the cost report separate guest-driven spend — which nothing we
        // decide bounds — from our own scheduled work. The context also
        // enforces the hard daily ceiling for this account.
        $response = AiCostContext::for(
            kind: $this->senderType === SenderType::GUEST
                ? AiTriggerKind::GUEST_MESSAGE
                : AiTriggerKind::STAFF_REQUEST,
            hotel: $this->hotel,
            trigger: $this->sender,
            callback: fn () => EventLogger::asAiAgent(
                fn () => $agent->prompt($messageText, attachments: $attachments)
            ),
        );

        $whatsApp->send($this->phoneNumber, $response->text);

        // Metered after the reply has gone out, and through safely(), so that
        // nothing about counting the message can stop the guest receiving it.
        // This is also the externally-triggered cost path: anyone who can
        // message the hotel's number can cause spend here.
        if ($this->hotel) {
            $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                hotel: $this->hotel,
                feature: MeterFeature::AI_MESSAGES,
                source: $this->sender,
                metadata: ['channel' => 'whatsapp', 'sender_type' => $this->senderType->value],
                actorKind: ActorKind::AI_AGENT,
            ));
        }
    }
}
