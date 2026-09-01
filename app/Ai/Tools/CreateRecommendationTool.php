<?php

namespace App\Ai\Tools;

use App\Enums\EvidenceLevel;
use App\Enums\RecommendationStatus;
use App\Models\Activity;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateRecommendationTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly Reservation $reservation,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Record an activity recommendation for the guest, so it can be tracked and later surfaced to them.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $activity = Activity::where('hotel_id', $this->hotel->id)
            ->active()
            ->find($request->string('activity_id')->toString());

        if (! $activity) {
            return 'No such active activity for this hotel. Only use activity ids returned by the activities tool.';
        }

        $recommendation = EventLogger::asAiAgent(fn () => Recommendation::create([
            'reservation_id' => $this->reservation->id,
            'activity_id' => $activity->id,
            'hotel_id' => $this->hotel->id,
            'reason' => $request->string('reason')->toString(),
            'predicted_confidence' => $request->float('predicted_confidence'),
            'priority' => $request->integer('priority', 0),
            'status' => RecommendationStatus::PENDING,
            'recommended_at' => now(),
            // A recommendation is a prediction about a guest — a hypothesis
            // until they act on it. The reservation and activity it rests on
            // are its evidence sources.
            'evidence_level' => EvidenceLevel::L3->value,
            'evidence_sources' => [$this->reservation->id, $activity->id],
        ]));

        return "Recommendation created (id: {$recommendation->id}) for activity '{$activity->name}'.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'activity_id' => $schema->string()
                ->description('The id of the recommended activity, from the activities tool.')
                ->required(),
            'reason' => $schema->string()
                ->description('Why this activity fits this guest, based on their party composition, room tier, reservation value, or messages.')
                ->required(),
            'predicted_confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->description('Your confidence that this guest will want this activity, from 0 (unlikely) to 1 (very likely).')
                ->required(),
            'priority' => $schema->integer()
                ->description('Rank among the recommendations you create for this guest in this run — 0 is the top recommendation, higher numbers are lower priority.')
                ->default(0),
        ];
    }
}
