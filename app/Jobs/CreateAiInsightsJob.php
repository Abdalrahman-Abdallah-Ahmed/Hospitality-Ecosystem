<?php

namespace App\Jobs;

use App\Ai\Agents\InsightsAgent;
use App\Enums\ActorKind;
use App\Enums\AiAgentName;
use App\Enums\AiInsightCategories;
use App\Enums\AiTriggerKind;
use App\Enums\EvidenceLevel;
use App\Enums\InsightTypes;
use App\Enums\MeterFeature;
use App\Models\AiInsights;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiActionRecorder;
use App\Support\Ai\AiCostContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;

class CreateAiInsightsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    private const MAX_CITATIONS = 10;

    /**
     * The meter's idempotency key, fixed when the job is created so every
     * retry of it carries the same one: insights are counted once per job.
     */
    public string $meterKey;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $data,
    ) {
        $this->meterKey = 'ai-insights:'.Str::uuid7();
    }

    /**
     * Execute the job.
     */
    public function handle(MeteringService $metering): void
    {
        $user = $this->data['user'];
        $hotel = $user->hotel;
        $insightType = $this->data['insight_type'] ?? InsightTypes::GENERAL->value;

        // Queue workers have no HTTP request, so TenantContext starts empty here
        // regardless of the queue driver — scope it explicitly to this hotel for
        // the duration of the job, restoring whatever context (if any) preceded it.
        TenantContext::runForHotel($hotel->id, function () use ($user, $hotel, $insightType, $metering) {
            $agent = InsightsAgent::make(
                user: $user,
            );

            // Our own work on our own timetable, so its cost sits under
            // scheduled_job — the half of the bill we control. Note that
            // generalInsights() makes several prompts; each one is logged,
            // and all of them are attributed to this same trigger.
            //
            // The insights are written inside the same context, so they are
            // part of the AI run that produced them (SPEC-083).
            $response = AiCostContext::for(
                kind: AiTriggerKind::SCHEDULED_JOB,
                hotel: $hotel,
                trigger: $hotel,
                agent: AiAgentName::INSIGHTS,
                callback: function () use ($agent, $insightType, $hotel, $user) {
                    $response = match ($insightType) {
                        InsightTypes::GENERAL->value => $this->generalInsights($agent),
                        InsightTypes::RESERVATION->value => $this->reservationInsights($agent),
                        InsightTypes::TASK->value => $agent->prompt('You are a helpful insights agent that provides insights about tasks.'),
                        default => $agent->prompt('You are a helpful insights agent.'),
                    };

                    AiActionRecorder::forJob(AiAgentName::INSIGHTS, self::class, $user, fn () => $this->saveInsights($response, $insightType, $hotel, $agent));

                    return $response;
                },
            );

            $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                hotel: $hotel,
                feature: MeterFeature::AI_INSIGHTS_GENERATED,
                quantity: count($response->structured['insights'] ?? []),
                source: $hotel,
                idempotencyKey: $this->meterKey,
                metadata: ['insight_type' => $insightType],
                actorKind: ActorKind::AI_AGENT,
            ));
        });
    }

    private function saveInsights(mixed $response, string $insightType, Hotel $hotel, InsightsAgent $agent): void
    {
        collect($response->structured['insights'])->each(function (array $insight) use ($insightType, $hotel, $agent) {
            $citations = $this->citationsFor($insight, $agent, $hotel);

            // A knowledge insight rests on its citations alone. With none
            // left to trace, it would be a claim with nothing behind it.
            if ($insight['category'] === AiInsightCategories::KNOWLEDGE->value && $citations === []) {
                Log::warning('Dropped a knowledge insight with no citation that traces to a source.', ['hotel_id' => $hotel->id]);

                return;
            }

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
                // Kept apart from the evidence: knowledge says how the hotel
                // says things work, so it never raises the level above.
                'citations' => $citations,
            ]);
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
            // Rests on knowledge alone: no record to be about, so it stays a
            // hypothesis (L3) whatever it cites.
            AiInsightCategories::KNOWLEDGE->value => null,
            default => null,
        };
    }

    /**
     * The insight's citations, traced back to sources (SPEC-090).
     *
     * A reference counts only if the knowledge tool returned it during this
     * run and its source still exists — the tool checks that once for the
     * whole run. Anything else is dropped, never shown as a citation nobody
     * can follow.
     *
     * @param  array<string, mixed>  $insight
     * @return list<array{source_type: string, source_id: string, title: ?string, location: ?string, scope: string, last_updated: ?string}>
     */
    private function citationsFor(array $insight, InsightsAgent $agent, Hotel $hotel): array
    {
        $references = collect($insight['citations'] ?? [])
            ->filter(fn ($reference) => is_string($reference))
            ->map(fn (string $reference) => strtoupper(trim($reference)))
            ->unique()
            ->values();

        $kept = $references
            ->map(fn (string $reference) => $agent->knowledgeTool->citation($reference))
            ->filter()
            ->take(self::MAX_CITATIONS)
            ->values();

        if ($kept->count() < $references->count()) {
            Log::warning('Dropped insight citations that did not trace to a live source returned in this run.', [
                'hotel_id' => $hotel->id,
                'dropped' => $references->count() - $kept->count(),
            ]);
        }

        return $kept->all();
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
