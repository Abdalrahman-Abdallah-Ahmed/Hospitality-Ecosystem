<?php

namespace App\Jobs;

use App\Ai\Agents\RecommendationAgent;
use App\Models\Reservation;
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
    public function handle(): void
    {
        $this->reservation->loadMissing(['guest', 'hotel']);

        $agent = RecommendationAgent::make(
            hotel: $this->reservation->hotel,
            reservation: $this->reservation,
        );

        $guestName = trim($this->reservation->guest->first_name.' '.$this->reservation->guest->last_name);

        $agent->prompt("Generate activity recommendations for guest {$guestName}.");
    }
}
