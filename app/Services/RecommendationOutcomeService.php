<?php

namespace App\Services;

use App\Enums\AttributionMethod;
use App\Enums\OutcomeType;
use App\Models\Booking;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only sanctioned way to write a recommendation outcome.
 *
 * Its job is enforcing the rules that keep the conversion figure honest: every
 * row states how we found out, the evidence level is derived from that rather
 * than supplied alongside it, a weaker source never overwrites a stronger one,
 * and an unsure classification is recorded as "delivered" instead of being
 * rounded up into an acceptance.
 */
class RecommendationOutcomeService
{
    public function record(
        Recommendation $recommendation,
        OutcomeType $outcome,
        AttributionMethod $method,
        ?Booking $booking = null,
        array $attributes = [],
    ): RecommendationOutcome {
        $this->guard($recommendation, $outcome, $method, $booking, $attributes);

        $outcome = $this->applyConfidenceFloor($outcome, $method, $attributes);

        $existing = $this->existingOutcome($recommendation);

        // A stronger method always wins; a weaker one never overwrites it.
        // This is what stops the nightly job replacing "the guest told us no"
        // with "they booked it anyway, probably".
        if ($existing && $existing->attribution_method->precedence() > $method->precedence()) {
            return $existing;
        }

        $occurredAt = isset($attributes['occurred_at'])
            ? Carbon::parse($attributes['occurred_at'])
            : ($booking?->created_at ?? Carbon::now());

        $payload = [
            'hotel_id' => $recommendation->hotel_id,
            'booking_id' => $booking?->id,
            'stay_id' => $booking?->stay_id ?? $recommendation->reservation?->stay?->id,
            'outcome' => $outcome->value,
            'attribution_method' => $method->value,
            // Derived, never accepted from the caller — see guard().
            'evidence_level' => $method->evidenceLevel()->value,
            'channel' => $attributes['channel'] ?? null,
            'recorded_by_user_id' => $attributes['recorded_by_user_id'] ?? null,
            // What was committed, not what was settled. Settlement is a
            // separate fact on the transactions linked to the booking.
            'expected_value' => $booking?->expected_value ?? $recommendation->activity?->price,
            'currency' => $booking?->currency ?? $recommendation->activity?->currency,
            'minutes_to_outcome' => $this->minutesToOutcome($recommendation, $occurredAt),
            'decline_reason' => $attributes['decline_reason'] ?? null,
            'evidence_quote' => $attributes['evidence_quote'] ?? null,
            'confidence' => $attributes['confidence'] ?? null,
            'context' => $attributes['context'] ?? null,
            'occurred_at' => $occurredAt,
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->refresh();
        }

        return RecommendationOutcome::create([
            ...$payload,
            'recommendation_id' => $recommendation->id,
        ]);
    }

    /**
     * The integrity rules. Each exists because breaking it produces a number
     * nobody can trust.
     */
    private function guard(
        Recommendation $recommendation,
        OutcomeType $outcome,
        AttributionMethod $method,
        ?Booking $booking,
        array $attributes,
    ): void {
        if (array_key_exists('evidence_level', $attributes)) {
            throw new RuntimeException(
                'evidence_level is derived from attribution_method and must not be supplied.'
            );
        }

        if ($outcome === OutcomeType::BOOKED && ! $booking) {
            throw new RuntimeException('A booked outcome must carry the booking that proves it.');
        }

        if ($method === AttributionMethod::INFERRED && ! $outcome->reachableByInference()) {
            throw new RuntimeException(
                "A {$outcome->value} outcome cannot be inferred — a matching job only ever sees bookings."
            );
        }

        if ($booking && $booking->hotel_id !== $recommendation->hotel_id) {
            throw new RuntimeException('The booking belongs to a different hotel.');
        }
    }

    /**
     * An unsure classification is recorded as DELIVERED, not as a guessed
     * decision. "We'll see" is not a yes, and politeness reads as agreement
     * far more readily in some languages than in English — rounding ambiguity
     * up into acceptance inflates every number downstream.
     */
    private function applyConfidenceFloor(
        OutcomeType $outcome,
        AttributionMethod $method,
        array $attributes,
    ): OutcomeType {
        if ($method !== AttributionMethod::CONVERSATIONAL) {
            return $outcome;
        }

        if (! in_array($outcome, [OutcomeType::ACCEPTED, OutcomeType::DECLINED], true)) {
            return $outcome;
        }

        $confidence = $attributes['confidence'] ?? null;
        $threshold = (float) config('recommendations.conversational.confidence_threshold');

        return $confidence !== null && (float) $confidence < $threshold
            ? OutcomeType::DELIVERED
            : $outcome;
    }

    private function existingOutcome(Recommendation $recommendation): ?RecommendationOutcome
    {
        return RecommendationOutcome::withoutGlobalScope('hotel')
            ->where('recommendation_id', $recommendation->id)
            ->first();
    }

    /**
     * How long the guest took to act, from when the recommendation was made.
     * Null when it cannot be computed — never zero, which would read as
     * "acted instantly".
     */
    private function minutesToOutcome(Recommendation $recommendation, Carbon $occurredAt): ?int
    {
        if (! $recommendation->recommended_at) {
            return null;
        }

        $minutes = $recommendation->recommended_at->diffInMinutes($occurredAt, absolute: false);

        return $minutes >= 0 ? (int) $minutes : null;
    }
}
