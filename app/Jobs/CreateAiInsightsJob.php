<?php

namespace App\Jobs;

use App\Ai\Agents\InsightsAgent;
use App\Enums\AiInsightCategories;
use App\Enums\EvidenceLevel;
use App\Enums\InsightTypes;
use App\Models\AiInsights;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Models\ConversationMessage;

class CreateAiInsightsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = $this->data['user'];
        $hotel = $user->hotel;
        $insightType = $this->data['insight_type'] ?? InsightTypes::GENERAL->value;

        // Queue workers have no HTTP request, so TenantContext starts empty here
        // regardless of the queue driver — scope it explicitly to this hotel for
        // the duration of the job, restoring whatever context (if any) preceded it.
        TenantContext::runForHotel($hotel->id, function () use ($user, $hotel, $insightType) {
            $agent = InsightsAgent::make(
                user: $user,
            );

            $response = match ($insightType) {
                InsightTypes::GENERAL->value => $this->generalInsights($agent),
                InsightTypes::RESERVATION->value => $this->reservationInsights($agent),
                InsightTypes::TASK->value => $agent->prompt('You are a helpful insights agent that provides insights about tasks.'),
                default => $agent->prompt('You are a helpful insights agent.'),
            };

            EventLogger::asAiAgent(function () use ($response, $insightType, $hotel) {
                collect($response->structured['insights'])->each(function (array $insight) use ($insightType, $hotel) {
                    $modelClass = $this->insightableModelFor($insight['category']);
                    $sourceBelongsToHotel = $modelClass && $this->sourceBelongsToHotel($insight['category'], $insight['source_id'], $hotel);

                    AiInsights::create([
                        'title' => $insight['title'],
                        'hotel_id' => $hotel->id,
                        'description' => $insight['description'],
                        'category' => $insight['category'],
                        'insight_type' => $insightType,
                        'insightable_type' => $sourceBelongsToHotel ? $modelClass : null,
                        'insightable_id' => $sourceBelongsToHotel ? $insight['source_id'] : null,
                        // A claim tied to a verified hotel record is a strong
                        // inference (L2); anything else stays a hypothesis (L3).
                        'evidence_level' => $sourceBelongsToHotel ? EvidenceLevel::L2->value : EvidenceLevel::L3->value,
                        'evidence_sources' => $sourceBelongsToHotel ? [$insight['source_id']] : null,
                    ]);
                });
            });
        });
    }

    private function generalInsights(InsightsAgent $agent)
    {
        return $agent->prompt('Provide up to 5 actionable insights about overall hotel operations, drawing from today\'s reservations, open tasks, and recent guest messages.');
    }

    private function reservationInsights(InsightsAgent $agent)
    {
        return $agent->prompt('provide insights about today\'s reservations.');
    }

    private function insightableModelFor(string $category): ?string
    {
        return match ($category) {
            AiInsightCategories::RESERVATION->value => Reservation::class,
            AiInsightCategories::TASK->value => Task::class,
            AiInsightCategories::GUEST_MESSAGE->value => ConversationMessage::class,
            AiInsightCategories::GENERAL->value => Hotel::class,
            default => null,
        };
    }

    /**
     * Verify a model-supplied source_id actually belongs to the given hotel,
     * since a hallucinated (or guest-message-injected) id could otherwise
     * point at another hotel's record despite existing in the database.
     */
    private function sourceBelongsToHotel(string $category, string $sourceId, Hotel $hotel): bool
    {
        return match ($category) {
            AiInsightCategories::RESERVATION->value => Reservation::where('hotel_id', $hotel->id)->whereKey($sourceId)->exists(),
            AiInsightCategories::TASK->value => Task::where('hotel_id', $hotel->id)->whereKey($sourceId)->exists(),
            AiInsightCategories::GUEST_MESSAGE->value => Guest::hotelConversationMessagesQuery($hotel)
                ->whereKey($sourceId)
                ->exists(),
            AiInsightCategories::GENERAL->value => $sourceId === (string) $hotel->id,
            default => false,
        };
    }
}
