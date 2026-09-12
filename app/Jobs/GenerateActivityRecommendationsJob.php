<?php

namespace App\Jobs;

use App\Ai\Agents\RecommendationAgent;
use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\MeterFeature;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Generates activity recommendations for a single reservation's guest —
 * triggered on demand (e.g. an admin clicking "recommend activities" for
 * that reservation), so recommendations stay grounded in that specific
 * guest's data rather than blended across a hotel-wide batch.
 */
class GenerateActivityRecommendationsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Reservation $reservation,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MeteringService $metering): void
    {
        $this->reservation->loadMissing(['guest', 'hotel']);

        $before = $this->recommendationCount();

        $agent = RecommendationAgent::make(
            hotel: $this->reservation->hotel,
            reservation: $this->reservation,
        );

        $guestName = trim($this->reservation->guest->first_name.' '.$this->reservation->guest->last_name);

        // Records this agent's tool-driven writes (recommendations) as ai_agent.
        //
        // Triggered on demand by a staff member (an admin asking for
        // recommendations for this reservation), not by a schedule — so its
        // cost is attributed to staff_request. The reservation is the trigger
        // record, which is what makes a per-reservation cost answerable.
        AiCostContext::for(
            kind: AiTriggerKind::STAFF_REQUEST,
            hotel: $this->reservation->hotel,
            trigger: $this->reservation,
            callback: fn () => EventLogger::asAiAgent(
                fn () => $agent->prompt("Generate activity recommendations for guest {$guestName}.")
            ),
        );

        // The agent writes recommendations through a tool, so the only honest
        // count is how many rows actually appeared — asking the model how
        // many it made would be taking its word for its own output.
        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $this->reservation->hotel,
            feature: MeterFeature::RECOMMENDATIONS_GENERATED,
            quantity: $this->recommendationCount() - $before,
            source: $this->reservation,
            actorKind: ActorKind::AI_AGENT,
        ));
    }

    /**
     * Counted without the tenant scope: this job runs on a queue worker with
     * no request behind it, so the scope would otherwise depend on whatever
     * context happened to be set.
     */
    private function recommendationCount(): int
    {
        return Recommendation::withoutGlobalScope('hotel')
            ->where('reservation_id', $this->reservation->getKey())
            ->count();
    }
}
