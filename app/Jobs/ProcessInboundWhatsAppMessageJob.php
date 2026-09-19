<?php

namespace App\Jobs;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\InboundMessageStatus;
use App\Enums\MeterFeature;
use App\Enums\SenderType;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Generates and sends the AI reply for an inbound WhatsApp message. Sender
 * recognition and the device-pairing check already happened synchronously
 * in WhatsAppController::whatsappWebhook() before this job was dispatched —
 * this job only does the slow part (the LLM call and the outbound send),
 * which is why it's queued rather than run inline in the webhook request.
 *
 * Generating and sending are separate steps with different retry rules:
 *
 * - The reply is generated at most once per message. The agent's tools
 *   write real records (bookings, tasks, reservations), so running the turn
 *   again after a failure could book the guest twice. A turn that fails, or
 *   that an earlier attempt started and never finished, gets an apology
 *   instead.
 * - The generated reply is stored on the inbound message before it is sent,
 *   so a failed send is retried on its own, with backoff, without asking
 *   the model again.
 */
class ProcessInboundWhatsAppMessageJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const NOT_PAIRED_REPLY = 'This number is not yet paired to your account. Please pair your WhatsApp device from the dashboard before using the advisor here.';

    public const UNKNOWN_SENDER_REPLY = "Sorry, we couldn't recognize this number. Please contact the hotel directly for assistance.";

    public const FAILED_REPLY = "Sorry, something went wrong on our side and I couldn't answer that. Please try again in a moment, or contact the hotel directly.";

    public const UNAVAILABLE_REPLY = "Sorry, I can't answer messages right now. Please contact the hotel directly for assistance.";

    /**
     * An agent turn with several tool calls can run well past a minute. Must
     * stay below the queue connection's retry_after, or the queue hands the
     * same job to a second worker while the first is still running it.
     */
    public int $timeout = 300;

    /** Failed sends retried before giving up. */
    public int $maxExceptions = 3;

    public function __construct(
        public WhatsAppInboundMessage $inbound,
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
     * One message per sender at a time: two quick messages must not race on
     * the same remembered conversation. The second waits and retries rather
     * than running alongside the first.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('whatsapp-sender:'.$this->phoneNumber))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    /**
     * Waiting behind the sender's previous message counts as a release, not
     * a failure, so the job is bounded by time rather than by attempts.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(WhatsAppMessageService $whatsApp, MeteringService $metering): void
    {
        $inbound = $this->inbound->fresh();

        if (! $inbound || $inbound->replied_at) {
            return;
        }

        if ($inbound->reply_text === null) {
            $inbound->update(['reply_text' => $this->replyFor($inbound, $whatsApp, $metering)]);
        }

        $whatsApp->send($this->phoneNumber, $inbound->reply_text);

        $inbound->update(['status' => InboundMessageStatus::REPLIED, 'replied_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->inbound->fresh()?->update(['status' => InboundMessageStatus::FAILED]);
    }

    private function replyFor(WhatsAppInboundMessage $inbound, WhatsAppMessageService $whatsApp, MeteringService $metering): string
    {
        if ($this->senderType === SenderType::ADMIN && ! $this->devicePaired) {
            return self::NOT_PAIRED_REPLY;
        }

        if ($this->senderType === SenderType::UNKNOWN) {
            return self::UNKNOWN_SENDER_REPLY;
        }

        // An earlier attempt started the turn and died before storing a
        // reply (worker killed, timeout). Its tool calls may already have
        // written records, so running it again is not safe.
        if ($inbound->generation_started_at) {
            return self::FAILED_REPLY;
        }

        $inbound->update(['generation_started_at' => now()]);

        try {
            $reply = $this->generate($whatsApp);
        } catch (AiSpendCeilingExceededException) {
            return self::UNAVAILABLE_REPLY;
        } catch (Throwable $e) {
            report($e);

            return self::FAILED_REPLY;
        }

        // Metered once, when the model actually answered — generation never
        // repeats for a message, so neither does this. Through safely(), so
        // nothing about counting the message can stop the guest receiving it.
        // This is also the externally-triggered cost path: anyone who can
        // message the hotel's number can cause spend here.
        if ($this->hotel) {
            $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                hotel: $this->hotel,
                feature: MeterFeature::AI_MESSAGES,
                source: $this->sender,
                idempotencyKey: 'whatsapp-inbound:'.$inbound->id,
                metadata: ['channel' => 'whatsapp', 'sender_type' => $this->senderType->value],
                actorKind: ActorKind::AI_AGENT,
            ));
        }

        return $reply;
    }

    private function generate(WhatsAppMessageService $whatsApp): string
    {
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

        // Queue workers have no HTTP request, so the tenant scope would
        // otherwise be unrestricted here. Every tool already filters by
        // hotel itself; scoping the whole turn means a tool that forgets to
        // still cannot reach another hotel's records.
        //
        // The concierge/advisor agents write records through their tools
        // (reservations, service-request tasks, recommendation updates) — all
        // of it belongs to the AI actor, not a human.
        //
        // Wrapped in a cost context as well: declaring the trigger here is
        // what lets the cost report separate guest-driven spend — which
        // nothing we decide bounds — from our own scheduled work. The context
        // also enforces the daily ceilings for this account.
        $response = TenantContext::runForHotel($this->hotel?->id, fn () => AiCostContext::for(
            kind: $this->senderType === SenderType::GUEST
                ? AiTriggerKind::GUEST_MESSAGE
                : AiTriggerKind::STAFF_REQUEST,
            hotel: $this->hotel,
            trigger: $this->sender,
            callback: fn () => EventLogger::asAiAgent(
                fn () => $agent->prompt($messageText, attachments: $attachments)
            ),
        ));

        return $response->text;
    }
}
