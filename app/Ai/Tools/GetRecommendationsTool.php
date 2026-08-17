<?php

namespace App\Ai\Tools;

use App\Models\Recommendation;
use App\Models\Reservation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetRecommendationsTool implements Tool
{
    public function __construct(
        private readonly ?Reservation $reservation,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Retrieve previously generated activity recommendations for the guest's own reservation, including the recommended activity, the reason it was suggested, predicted confidence, and current status. Never returns another guest's recommendations.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'No reservation found for this guest.';
        }

        $recommendations = Recommendation::where('reservation_id', $this->reservation->id)
            ->with('activity')
            ->orderByDesc('recommended_at')
            ->get()
            ->map(fn (Recommendation $recommendation) => [
                'id' => $recommendation->id,
                'activity_id' => $recommendation->activity_id,
                'activity_name' => $recommendation->activity?->name,
                'price' => $recommendation->activity?->price,
                'currency' => $recommendation->activity?->currency,
                'reason' => $recommendation->reason,
                'predicted_confidence' => $recommendation->predicted_confidence,
                'guest_confidence' => $recommendation->guest_confidence,
                'priority' => $recommendation->priority,
                'status' => $recommendation->status->value,
                'recommended_at' => $recommendation->recommended_at?->toDateTimeString(),
                'accepted_at' => $recommendation->accepted_at?->toDateTimeString(),
                'rejected_at' => $recommendation->rejected_at?->toDateTimeString(),
                'dismissed_at' => $recommendation->dismissed_at?->toDateTimeString(),
            ])
            ->values();

        return json_encode($recommendations);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
