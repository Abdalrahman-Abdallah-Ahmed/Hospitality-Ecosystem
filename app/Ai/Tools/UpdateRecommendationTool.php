<?php

namespace App\Ai\Tools;

use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateRecommendationTool implements Tool
{
    public function __construct(
        private readonly ?Reservation $reservation,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Record the guest's reaction to a previously generated recommendation (from the get-recommendations tool) — mark it accepted, rejected, or dismissed, and/or record how confident the guest seemed. Only affects the guest's own recommendations.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'No reservation found for this guest.';
        }

        $recommendation = Recommendation::where('reservation_id', $this->reservation->id)
            ->find($request->string('recommendation_id')->toString());

        if (! $recommendation) {
            return 'No such recommendation for this guest. Only use recommendation ids returned by the get-recommendations tool.';
        }

        $action = $request->string('action')->toString() ?: null;
        $guestConfidence = $request->filled('guest_confidence') ? $request->float('guest_confidence') : null;

        if (! $action && $guestConfidence === null) {
            return 'Nothing to update — provide an action and/or a guest_confidence.';
        }

        $updates = match ($action) {
            'accepted' => ['status' => RecommendationStatus::ACCEPTED, 'accepted_at' => now()],
            'rejected' => ['status' => RecommendationStatus::REJECTED, 'rejected_at' => now()],
            'dismissed' => ['status' => RecommendationStatus::IGNORED, 'dismissed_at' => now()],
            default => [],
        };

        if ($guestConfidence !== null) {
            $updates['guest_confidence'] = $guestConfidence;
        }

        EventLogger::asAiAgent(fn () => $recommendation->update($updates));

        return "Recommendation updated (id: {$recommendation->id}).";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendation_id' => $schema->string()
                ->description('The id of the recommendation to update, from the get-recommendations tool.')
                ->required(),
            'action' => $schema->string()
                ->enum(['accepted', 'rejected', 'dismissed'])
                ->description('accepted: the guest wants it and staff should help them book it. rejected: the guest explicitly said no. dismissed: the guest showed no interest either way.'),
            'guest_confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->description('How confident/interested the guest seemed, from 0 (not interested) to 1 (very interested).'),
        ];
    }
}
