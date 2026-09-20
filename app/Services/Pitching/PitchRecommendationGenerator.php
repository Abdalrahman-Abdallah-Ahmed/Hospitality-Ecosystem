<?php

namespace App\Services\Pitching;

use App\Ai\Agents\RecommendationAgent;
use App\Enums\ActorKind;
use App\Enums\MeterFeature;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\Metering\MeteringService;
use App\Support\Audit\EventLogger;

/**
 * Generates recommendations the first time a guest turn needs them and the
 * reservation has never had any.
 *
 * Runs inside the guest message's own AI cost context, which is already open
 * when a turn reaches this — so the spend is filed as guest-driven, the same
 * as the turn classifier's, rather than as the staff_request-triggered batch
 * GenerateActivityRecommendationsJob records when an admin asks directly.
 *
 * Once per reservation, ever: a reservation that has been generated for
 * before is never generated for again here, even once every recommendation
 * it got is delivered or refused. Regenerating a stale batch is a deliberate
 * follow-up, not this.
 */
class PitchRecommendationGenerator
{
    public function __construct(
        private readonly MeteringService $metering,
    ) {}

    /**
     * @throws \Throwable on any failure — the caller decides what an empty
     *                    shortlist means for the turn; this never blocks the reply itself
     */
    public function ensureGenerated(Stay $stay): void
    {
        if ($this->alreadyGenerated($stay)) {
            return;
        }

        $reservation = $stay->reservation()->with(['guest', 'hotel'])->first();

        if (! $reservation) {
            return;
        }

        $before = $this->countFor($reservation);
        $guestName = trim($reservation->guest->first_name.' '.$reservation->guest->last_name);

        $agent = RecommendationAgent::make(hotel: $reservation->hotel, reservation: $reservation);

        EventLogger::asAiAgent(fn () => $agent->prompt("Generate activity recommendations for guest {$guestName}."));

        // The agent writes recommendations through a tool, so the only honest
        // count is how many rows actually appeared.
        $this->metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $reservation->hotel,
            feature: MeterFeature::RECOMMENDATIONS_GENERATED,
            quantity: $this->countFor($reservation) - $before,
            source: $reservation,
            actorKind: ActorKind::AI_AGENT,
        ));
    }

    private function alreadyGenerated(Stay $stay): bool
    {
        return Recommendation::withoutGlobalScope('hotel')
            ->where('reservation_id', $stay->reservation_id)
            ->exists();
    }

    private function countFor(Reservation $reservation): int
    {
        return Recommendation::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->count();
    }
}
