<?php

namespace App\Jobs;

use App\Enums\ActorKind;
use App\Enums\MeterFeature;
use App\Enums\PitchGate;
use App\Enums\PitchOpening;
use App\Enums\PitchResult;
use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveSkipReason;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\Metering\MeteringService;
use App\Services\Pitching\PitchCoordinator;
use App\Services\Pitching\PitchEligibilityService;
use App\Services\Proactive\ProactiveGuardrails;
use App\Services\Proactive\ProactiveMessageRenderer;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\ConversationAppender;
use App\Support\PhoneNumber;
use App\Support\Pitching\GateReport;
use App\Support\Proactive\GuardrailResult;
use App\Support\Proactive\PitchNotStaged;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one proactive message, or decides it must wait or be dropped
 * (SPEC-073). Modelled on SendGuestRequestNoticeJob:
 *
 * - At most once: the job claims the row (scheduled → sending) before doing
 *   anything; a claim left by a dead worker is taken over after a while.
 * - Every guardrail is checked now, against current data.
 * - The text is fixed and filled from records; no AI is called.
 * - A pitch is recorded like any other (a PitchDecision with the proactive
 *   opening), and counts as delivered only once WhatsApp accepted it.
 * - Failures are recorded, never thrown.
 */
class SendProactiveMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $messageId) {}

    public function handle(
        WhatsAppMessageService $whatsApp,
        ProactiveGuardrails $guardrails,
        ProactiveMessageRenderer $renderer,
        MeteringService $metering,
    ): void {
        $staleBefore = now()->subMinutes((int) config('proactive.stale_claim_minutes', 5));

        $claimed = ProactiveMessage::withoutGlobalScope('hotel')
            ->whereKey($this->messageId)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', ProactiveMessageStatus::SCHEDULED->value)->where('due_at', '<=', now()))
                ->orWhere(fn ($query) => $query->where('status', ProactiveMessageStatus::SENDING->value)->where('updated_at', '<', $staleBefore)))
            ->update(['status' => ProactiveMessageStatus::SENDING->value, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        // The only unscoped read: the hotel is not known until the row is.
        $message = ProactiveMessage::withoutGlobalScope('hotel')->with('guest')->find($this->messageId);

        if (! $message) {
            return;
        }

        // One message per guest at a time: the daily cap and the pitch rules
        // read what was already sent, so two of this guest's messages checked
        // side by side could both pass. The second waits a minute instead.
        $lock = Cache::lock('proactive-guest:'.$message->guest_id, $this->timeout);

        if (! $lock->get()) {
            $message->forceFill(['status' => ProactiveMessageStatus::SCHEDULED, 'due_at' => now()->addMinute()])->save();

            return;
        }

        try {
            TenantContext::runForHotel($message->hotel_id, function () use ($message, $whatsApp, $guardrails, $renderer, $metering): void {
                try {
                    $this->process($message, $whatsApp, $guardrails, $renderer, $metering);
                } catch (Throwable $e) {
                    report($e);

                    // Never overwrite a message the guest already received.
                    if ($message->status !== ProactiveMessageStatus::SENT) {
                        $this->finish($message, ProactiveMessageStatus::FAILED, ProactiveSkipReason::SEND_FAILED);
                    }
                }
            });
        } finally {
            $lock->release();
        }
    }

    private function process(
        ProactiveMessage $message,
        WhatsAppMessageService $whatsApp,
        ProactiveGuardrails $guardrails,
        ProactiveMessageRenderer $renderer,
        MeteringService $metering,
    ): void {
        $decision = $guardrails->check($message, now());

        if ($decision->defers()) {
            $message->forceFill([
                'status' => ProactiveMessageStatus::SCHEDULED,
                'reason' => $decision->reason,
                'due_at' => $decision->until,
            ])->save();

            return;
        }

        if (! $decision->sends()) {
            $this->finish($message, ProactiveMessageStatus::SKIPPED, $decision->reason);

            return;
        }

        $hotel = $decision->hotel ?? Hotel::find($message->hotel_id);
        $guest = $message->guest;
        $rendered = $renderer->render($message->trigger, $guest, $hotel, $decision->recommendation, $decision->booking);

        if ($rendered === null) {
            $this->finish($message, ProactiveMessageStatus::SKIPPED, ProactiveSkipReason::MISSING_DATA);

            return;
        }

        $pitch = null;

        if ($message->trigger->isPitch()) {
            $pitch = $this->stagePitch($message, $guest, $hotel, $decision);

            if ($pitch instanceof ProactiveSkipReason) {
                $this->finish($message, ProactiveMessageStatus::SKIPPED, $pitch);

                return;
            }
        }

        try {
            $whatsApp->send(PhoneNumber::digits($guest->phone_number), $rendered['body']);
        } catch (Throwable $e) {
            report($e);
            $this->sendFailed($message, $pitch);

            return;
        }

        $conversationId = rescue(fn () => app(ConversationAppender::class)->appendAssistantMessage($guest, $rendered['body']), report: true);

        $message->forceFill([
            'status' => ProactiveMessageStatus::SENT,
            'reason' => null,
            'locale' => $rendered['locale'],
            'body' => $rendered['body'],
            'conversation_id' => $conversationId,
            // A milestone offer picks its recommendation only now.
            'recommendation_id' => $decision->recommendation?->id ?? $message->recommendation_id,
            'sent_at' => now(),
            'attempts' => $message->attempts + 1,
        ])->save();

        // The guest has the message; what follows is bookkeeping, and a
        // failure in it must not make the log say it was never sent.
        if ($pitch) {
            rescue(fn () => app(PitchCoordinator::class)->markPitched($pitch, $rendered['body'], ActorKind::SYSTEM), report: true);
        }

        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $hotel,
            feature: MeterFeature::PROACTIVE_MESSAGES_SENT,
            source: $message,
            idempotencyKey: 'proactive:'.$message->id,
            metadata: ['trigger' => $message->trigger->value],
            actorKind: ActorKind::SYSTEM,
        ));
    }

    /**
     * Record the pitch before sending, the way a guest turn stages one, so
     * the cap and the retry rule already see it while the send is in flight.
     * Returns why it cannot be staged when a guest turn, or a change to the
     * recommendation, got there first.
     */
    private function stagePitch(ProactiveMessage $message, Guest $guest, Hotel $hotel, GuardrailResult $decision): PitchDecision|ProactiveSkipReason
    {
        try {
            return DB::transaction(fn () => $this->recordPitch($message, $guest, $hotel, $decision));
        } catch (PitchNotStaged $e) {
            return $e->reason;
        }
    }

    /**
     * Under the reservation's lock — the same one a guest turn's pitch tool
     * takes — the pitch gates are checked again, so a live turn and this
     * message cannot both pitch past the reservation's budget.
     *
     * @throws PitchNotStaged which rolls the decision back
     */
    private function recordPitch(ProactiveMessage $message, Guest $guest, Hotel $hotel, GuardrailResult $decision): PitchDecision
    {
        Reservation::withoutGlobalScope('hotel')->whereKey($message->reservation_id)->lockForUpdate()->first();
        $stay = Stay::find($message->stay_id);
        $eligibility = app(PitchEligibilityService::class);
        $report = $stay ? $eligibility->evaluateOpeningGates($stay, PitchOpening::PROACTIVE, new GateReport) : null;

        if (! $report?->passed()) {
            throw new PitchNotStaged($report?->blocked(PitchGate::RETRY_USED) ? ProactiveSkipReason::RETRY_USED
                : ($report?->blocked(PitchGate::RETRY_WINDOW_CLOSED) ? ProactiveSkipReason::RETRY_WINDOW_CLOSED
                : ($report?->blocked(PitchGate::OPTED_OUT) ? ProactiveSkipReason::OPTED_OUT : ProactiveSkipReason::PITCH_CAP)));
        }

        $isRetry = $eligibility->pitchFlow($stay, now())->isRetryNext();

        $pitch = PitchDecision::create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'stay_id' => $message->stay_id,
            'proactive_message_id' => $message->id,
            'eligible' => true,
            'gates' => $decision->gates?->toArray() ?? [],
            'classifier_ran' => false,
            'opening' => PitchOpening::PROACTIVE,
            'explicit_request' => false,
            'candidates' => $decision->candidates?->toArray(),
            'rules_version' => config('pitching.rules_version'),
            'decided_at' => now(),
            'is_retry' => $isRetry,
        ]);

        $staged = Recommendation::query()
            ->whereKey($decision->recommendation->id)
            ->offerable()
            ->update(['pitch_decision_id' => $pitch->id]) === 1;

        if (! $staged) {
            throw new PitchNotStaged(ProactiveSkipReason::RECOMMENDATION_NOT_OFFERABLE);
        }

        return $pitch;
    }

    private function sendFailed(ProactiveMessage $message, ?PitchDecision $pitch): void
    {
        $attempts = $message->attempts + 1;
        $retry = $attempts < (int) config('proactive.max_send_attempts', 2);

        // A failed send never reached the guest: the staged pitch is released
        // so it neither counts toward a cap nor blocks the retry.
        if ($pitch) {
            Recommendation::withoutGlobalScope('hotel')->where('pitch_decision_id', $pitch->id)->update(['pitch_decision_id' => null]);
            $pitch->update(['result' => PitchResult::REPLY_FAILED, 'completed_at' => now()]);
        }

        $message->forceFill([
            'attempts' => $attempts,
            'status' => $retry ? ProactiveMessageStatus::SCHEDULED : ProactiveMessageStatus::FAILED,
            'reason' => ProactiveSkipReason::SEND_FAILED,
            'due_at' => $retry ? now()->addMinutes(5) : $message->due_at,
        ])->save();
    }

    private function finish(ProactiveMessage $message, ProactiveMessageStatus $status, ?ProactiveSkipReason $reason): void
    {
        $message->forceFill(['status' => $status, 'reason' => $reason])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $message = ProactiveMessage::withoutGlobalScope('hotel')
            ->whereKey($this->messageId)
            ->where('status', ProactiveMessageStatus::SENDING->value)
            ->first();

        if ($message) {
            TenantContext::runForHotel($message->hotel_id, fn () => $this->finish($message, ProactiveMessageStatus::FAILED, ProactiveSkipReason::SEND_FAILED));
        }
    }
}
