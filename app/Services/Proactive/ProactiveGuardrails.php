<?php

namespace App\Services\Proactive;

use App\Enums\BookingStatus;
use App\Enums\PitchGate;
use App\Enums\PitchOpening;
use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveSkipReason;
use App\Enums\ProactiveTrigger;
use App\Enums\RecommendationStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\Stay;
use App\Services\Pitching\PitchEligibilityService;
use App\Support\PhoneNumber;
use App\Support\Pitching\GateReport;
use App\Support\Proactive\GuardrailResult;
use App\Support\WhatsApp\MessagingWindow;
use Carbon\CarbonInterface;

/**
 * May this proactive message go out right now? (SPEC-073 FR-026–FR-031)
 *
 * Checked when the message is about to be sent, against current data — not
 * when it was scheduled — so a check-out, a cancelled booking, an opt-out or
 * a withdrawn approval in between stops it. The order is fixed: the rules
 * that end a message come before the ones that only postpone it, so a
 * message that can never go out is not deferred first.
 */
class ProactiveGuardrails
{
    public function __construct(
        private readonly PitchEligibilityService $eligibility,
    ) {}

    public function check(ProactiveMessage $message, CarbonInterface $now): GuardrailResult
    {
        $hotel = Hotel::find($message->hotel_id);
        $guest = $message->guest;
        $settings = $hotel?->proactiveSettings();

        if (! $hotel || ! $guest || ! $settings->enabled) {
            return GuardrailResult::skip(ProactiveSkipReason::DISABLED);
        }

        if (! $settings->triggerEnabled($message->trigger)) {
            return GuardrailResult::skip(ProactiveSkipReason::TRIGGER_DISABLED);
        }

        if ($now->greaterThan($message->valid_until)) {
            return GuardrailResult::skip(ProactiveSkipReason::EXPIRED);
        }

        if ($guest->isProactiveOptedOut()) {
            return GuardrailResult::skip(ProactiveSkipReason::OPTED_OUT);
        }

        $phone = PhoneNumber::digits($guest->phone_number);

        if (! $phone) {
            return GuardrailResult::skip(ProactiveSkipReason::NO_WHATSAPP);
        }

        // Outside the 24-hour window nothing goes out, on any channel, and
        // the message is not revived later (SPEC-073 Q1).
        if (! MessagingWindow::isOpen($phone, $now)) {
            return GuardrailResult::skip(ProactiveSkipReason::OUTSIDE_WINDOW);
        }

        $send = $message->trigger->isPitch()
            ? $this->pitch($message, $hotel, $now)
            : $this->reminder($message);

        if (! $send->sends()) {
            return $send;
        }

        $send = $send->withHotel($hotel);
        $local = $now->copy()->setTimezone($hotel->timezone ?: 'UTC');

        if ($settings->inQuietHours($local)) {
            return $this->deferOrExpire($message, ProactiveSkipReason::QUIET_HOURS, $settings->quietHoursEnd($local));
        }

        $lastInbound = MessagingWindow::lastInboundAt($phone);
        $activeMinutes = (int) config('proactive.guest_active_minutes', 30);

        if ($lastInbound && $lastInbound->greaterThan($now->copy()->subMinutes($activeMinutes))) {
            return $this->deferOrExpire($message, ProactiveSkipReason::GUEST_ACTIVE, $lastInbound->copy()->addMinutes($activeMinutes));
        }

        if ($this->dailyCapReached($message, $local, $settings->dailyCap) || $this->reminderComesFirst($message, $local)) {
            $tomorrow = $local->copy()->addDay()->setTimeFromTimeString($settings->milestoneTime)->startOfMinute();

            return $this->deferOrExpire($message, ProactiveSkipReason::DAILY_CAP, $tomorrow);
        }

        return $send;
    }

    /**
     * A booking reminder needs only a still-confirmed booking and a guest who
     * is, or will be, at the hotel for it.
     */
    private function reminder(ProactiveMessage $message): GuardrailResult
    {
        $booking = Booking::with('activity')->find($message->booking_id);

        if (! $booking || $booking->status !== BookingStatus::CONFIRMED) {
            return GuardrailResult::skip(ProactiveSkipReason::BOOKING_NOT_CONFIRMED);
        }

        $present = Stay::query()
            ->where('reservation_id', $booking->reservation_id ?? $message->reservation_id)
            ->whereIn('status', [StayStatus::IN_HOUSE->value, StayStatus::EXPECTED->value])
            ->exists();

        return $present ? GuardrailResult::send(booking: $booking) : GuardrailResult::skip(ProactiveSkipReason::NOT_IN_HOUSE);
    }

    /**
     * Every rule a contextual pitch obeys, with the proactive opening — so the
     * cap, the decline-retry rule and the opt-out apply exactly as in chat.
     */
    private function pitch(ProactiveMessage $message, Hotel $hotel, CarbonInterface $now): GuardrailResult
    {
        $stay = Stay::find($message->stay_id);

        if (! $stay) {
            return GuardrailResult::skip(ProactiveSkipReason::NOT_IN_HOUSE);
        }

        $flow = $this->eligibility->pitchFlow($stay, $now);

        if ($message->trigger === ProactiveTrigger::RECOMMENDATION_APPROVED && ($flow->pitchesTowardCap > 0 || $flow->declined)) {
            return GuardrailResult::skip(ProactiveSkipReason::ALREADY_PITCHED);
        }

        $report = $this->eligibility->evaluateCheapGates($message->guest, $hotel, $stay, $now);
        $report = $this->eligibility->evaluateOpeningGates($stay, PitchOpening::PROACTIVE, $report, $now);

        if (! $report->passed()) {
            return GuardrailResult::skip($this->reasonFor($report));
        }

        $candidates = $this->eligibility->candidates($hotel, $stay, null, $now);

        // An approved-recommendation message offers its own recommendation,
        // whether or not it made the (truncated) shortlist; a milestone
        // offers the top of the shortlist.
        $recommendation = match ($message->trigger) {
            ProactiveTrigger::RECOMMENDATION_APPROVED => $this->stillOfferable($message, $stay),
            default => ($top = $candidates->shortlist[0] ?? null) ? Recommendation::with('activity')->find($top->recommendationId) : null,
        };

        if (! $recommendation) {
            return GuardrailResult::skip($message->trigger === ProactiveTrigger::RECOMMENDATION_APPROVED
                ? ProactiveSkipReason::RECOMMENDATION_NOT_OFFERABLE
                : ProactiveSkipReason::NO_CANDIDATE);
        }

        return GuardrailResult::send($recommendation, $report, $candidates, $flow->isRetryNext());
    }

    /**
     * The message's own recommendation, if it can still be offered: approved,
     * never offered, activity active, and not an activity the guest declined
     * during this reservation.
     */
    private function stillOfferable(ProactiveMessage $message, Stay $stay): ?Recommendation
    {
        $recommendation = Recommendation::query()->whereKey($message->recommendation_id)->offerable()->with('activity')->first();

        if (! $recommendation) {
            return null;
        }

        $declined = Recommendation::query()
            ->where('reservation_id', $stay->reservation_id)
            ->where('activity_id', $recommendation->activity_id)
            ->where('status', RecommendationStatus::REJECTED->value)
            ->exists();

        return $declined ? null : $recommendation;
    }

    private function reasonFor(GateReport $report): ProactiveSkipReason
    {
        $map = [
            PitchGate::FEATURE_DISABLED->value => ProactiveSkipReason::PITCHING_DISABLED,
            PitchGate::NO_STAY->value => ProactiveSkipReason::NOT_IN_HOUSE,
            PitchGate::NOT_IN_HOUSE->value => ProactiveSkipReason::NOT_IN_HOUSE,
            PitchGate::DEPARTING->value => ProactiveSkipReason::DEPARTING,
            PitchGate::ESCALATED_THIS_STAY->value => ProactiveSkipReason::ESCALATED,
            PitchGate::OPEN_SERVICE_REQUEST->value => ProactiveSkipReason::OPEN_COMPLAINT,
            PitchGate::OPTED_OUT->value => ProactiveSkipReason::OPTED_OUT,
            PitchGate::PITCH_CAP->value => ProactiveSkipReason::PITCH_CAP,
            PitchGate::RETRY_USED->value => ProactiveSkipReason::RETRY_USED,
            PitchGate::RETRY_WINDOW_CLOSED->value => ProactiveSkipReason::RETRY_WINDOW_CLOSED,
        ];

        foreach ($report->toArray() as $gate) {
            if (! $gate['passed']) {
                return $map[$gate['gate']] ?? ProactiveSkipReason::PITCHING_DISABLED;
            }
        }

        return ProactiveSkipReason::PITCHING_DISABLED;
    }

    private function dailyCapReached(ProactiveMessage $message, CarbonInterface $local, int $cap): bool
    {
        return ProactiveMessage::query()
            ->where('hotel_id', $message->hotel_id)
            ->where('guest_id', $message->guest_id)
            ->where('status', ProactiveMessageStatus::SENT->value)
            ->whereBetween('sent_at', [$local->copy()->startOfDay()->utc(), $local->copy()->endOfDay()->utc()])
            ->count() >= $cap;
    }

    /**
     * A booking reminder due today goes before a pitch (FR-028).
     */
    private function reminderComesFirst(ProactiveMessage $message, CarbonInterface $local): bool
    {
        return $message->trigger->isPitch() && ProactiveMessage::query()
            ->where('hotel_id', $message->hotel_id)
            ->where('guest_id', $message->guest_id)
            ->where('trigger', ProactiveTrigger::UPCOMING_ACTIVITY->value)
            ->whereIn('status', [ProactiveMessageStatus::SCHEDULED->value, ProactiveMessageStatus::SENDING->value])
            ->where('due_at', '<=', $local->copy()->endOfDay()->utc())
            ->exists();
    }

    /**
     * Postpone the message — or, when it would only be allowed after it
     * stops being worth sending, drop it with the reason that held it back,
     * which says more to an admin than "expired".
     */
    private function deferOrExpire(ProactiveMessage $message, ProactiveSkipReason $reason, CarbonInterface $until): GuardrailResult
    {
        return $until->greaterThan($message->valid_until)
            ? GuardrailResult::skip($reason)
            : GuardrailResult::defer($reason, $until);
    }
}
