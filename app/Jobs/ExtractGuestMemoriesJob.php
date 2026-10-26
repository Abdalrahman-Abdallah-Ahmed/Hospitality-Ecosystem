<?php

namespace App\Jobs;

use App\Ai\Agents\GuestMemoryAgent;
use App\Enums\ActorKind;
use App\Enums\AiAgentName;
use App\Enums\AiTriggerKind;
use App\Enums\GuestMemoryExtractionStatus;
use App\Enums\MeterFeature;
use App\Enums\QueueLane;
use App\Enums\StayStatus;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Models\GuestMemoryExtraction;
use App\Models\Hotel;
use App\Models\Stay;
use App\Services\Guests\GuestMemoryService;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiActionRecorder;
use App\Support\Ai\AiCostContext;
use App\Support\Ai\AiRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

/**
 * Extracts what is worth remembering about a guest from what they and the
 * Concierge said during their stay, once, after their last check-out on a
 * reservation (SPEC-084).
 *
 * Once: the extraction row is unique per guest and reservation, and only a
 * new row or one due for its retry is claimed. A round makes three quick
 * attempts; if they all fail, or the spend ceiling stops it, it gets exactly
 * one more round about a day later (RetryGuestMemoryExtractionsJob), then
 * the result is final (FR-036).
 *
 * Forward only: dispatched by a check-out (Stay), never for stays that
 * departed before this shipped — there is no backfill.
 */
class ExtractGuestMemoriesJob implements ShouldQueue
{
    use Queueable;

    /** Most messages read from one stay; the newest win. */
    private const MAX_MESSAGES = 200;

    /** Rounds in all: the first, then the one retry a day later. */
    private const ROUNDS = 2;

    public int $tries = 1;

    public function __construct(
        public string $hotelId,
        public string $guestId,
        public string $reservationId,
    ) {
        // LLM or embedding work: it must never hold up a guest reply (spec 017 FR-020).
        $this->onQueue(QueueLane::AI_BATCH->value);
    }

    public function handle(GuestMemoryService $memories): void
    {
        TenantContext::runForHotel($this->hotelId, function () use ($memories) {
            $guest = Guest::query()->find($this->guestId);
            $hotel = Hotel::query()->find($this->hotelId);

            if ($guest === null || $hotel === null || $this->guestStillInHouse()) {
                return;
            }

            $extraction = $this->claim();

            if ($extraction === null) {
                return;
            }

            if (! $hotel->guest_memories_enabled) {
                $this->finish($extraction, GuestMemoryExtractionStatus::SKIPPED_DISABLED);

                return;
            }

            $stays = $this->guestStays();
            $transcript = $this->transcript($guest, $stays);

            if ($transcript === null) {
                $this->finish($extraction, GuestMemoryExtractionStatus::SKIPPED_NO_MESSAGES);

                return;
            }

            $this->extract($extraction, $guest, $hotel, $stays->last(), $transcript, $memories);
        });
    }

    private function extract(GuestMemoryExtraction $extraction, Guest $guest, Hotel $hotel, Stay $sourceStay, string $transcript, GuestMemoryService $memories): void
    {
        $runId = null;

        try {
            $written = AiCostContext::for(
                kind: AiTriggerKind::SCHEDULED_JOB,
                hotel: $hotel,
                trigger: $guest,
                agent: AiAgentName::GUEST_MEMORY,
                callback: function () use ($guest, $sourceStay, $transcript, $memories, &$runId) {
                    $runId = AiRun::id();

                    $response = retry(3, fn () => GuestMemoryAgent::make()->prompt($this->prompt($guest, $transcript)), 1000,
                        fn (Throwable $e) => ! $e instanceof AiSpendCeilingExceededException);

                    return AiActionRecorder::forJob(
                        AiAgentName::GUEST_MEMORY,
                        self::class,
                        null,
                        fn () => $memories->apply($guest, $sourceStay, $response->structured['operations'] ?? []),
                    );
                },
            );
        } catch (AiSpendCeilingExceededException) {
            $this->stopped($extraction, GuestMemoryExtractionStatus::SKIPPED_SPEND_CEILING, 'spend_ceiling', $runId);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->stopped($extraction, GuestMemoryExtractionStatus::FAILED, class_basename($e), $runId);

            return;
        }

        // Counted once per completed extraction, memories found or not: the
        // AI read the conversation either way (SPEC-091). Keyed on the
        // extraction, which is unique per guest and reservation, so a retry
        // round or a re-run never counts it twice.
        app(MeteringService::class)->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $hotel,
            feature: MeterFeature::GUEST_MEMORY_EXTRACTIONS,
            source: $guest,
            idempotencyKey: 'guest-memory-extraction:'.$extraction->id,
            metadata: ['memories_written' => $written],
            actorKind: ActorKind::AI_AGENT,
        ));

        $this->finish($extraction, GuestMemoryExtractionStatus::EXTRACTED, ['memories_written' => $written, 'ai_run_id' => $runId]);
    }

    /**
     * Claim the extraction for this round: create it, or take it if its
     * retry is due. Anything else (running, or already final) is not ours,
     * which is what makes every later trigger a no-op.
     */
    private function claim(): ?GuestMemoryExtraction
    {
        GuestMemoryExtraction::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'hotel_id' => $this->hotelId,
            'guest_id' => $this->guestId,
            'reservation_id' => $this->reservationId,
            // Due at once: a new extraction is claimed exactly like a retry.
            'status' => GuestMemoryExtractionStatus::PENDING_RETRY->value,
            'retry_at' => now(),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimed = GuestMemoryExtraction::query()
            ->where('hotel_id', $this->hotelId)
            ->where('guest_id', $this->guestId)
            ->where('reservation_id', $this->reservationId)
            ->where('status', GuestMemoryExtractionStatus::PENDING_RETRY->value)
            ->where('retry_at', '<=', now())
            ->toBase()
            ->update([
                'status' => GuestMemoryExtractionStatus::RUNNING->value,
                'attempts' => DB::raw('attempts + 1'),
                'retry_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        return GuestMemoryExtraction::query()
            ->where('guest_id', $this->guestId)
            ->where('reservation_id', $this->reservationId)
            ->first();
    }

    /**
     * Blocked or failed: once more a day later on the first round, final on
     * the last.
     */
    private function stopped(GuestMemoryExtraction $extraction, GuestMemoryExtractionStatus $final, string $reason, ?string $runId): void
    {
        $retry = $extraction->attempts < self::ROUNDS;

        $this->finish($extraction, $retry ? GuestMemoryExtractionStatus::PENDING_RETRY : $final, [
            'retry_at' => $retry ? now()->addDay() : null,
            'last_error' => Str::limit($reason, 255, ''),
            'ai_run_id' => $runId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(GuestMemoryExtraction $extraction, GuestMemoryExtractionStatus $status, array $attributes = []): void
    {
        $extraction->forceFill(['status' => $status, ...$attributes])->save();
    }

    /**
     * The guest's stays on this reservation, oldest first.
     *
     * @return Collection<int, Stay>
     */
    private function guestStays(): Collection
    {
        return Stay::query()
            ->where('reservation_id', $this->reservationId)
            ->where('guest_id', $this->guestId)
            ->whereNotNull('checked_out_at')
            ->orderBy('checked_out_at')
            ->get();
    }

    /**
     * Only the last of the guest's rooms on the reservation runs the
     * extraction, so one conversation is read once.
     */
    private function guestStillInHouse(): bool
    {
        return Stay::query()
            ->where('reservation_id', $this->reservationId)
            ->where('guest_id', $this->guestId)
            ->where('status', StayStatus::IN_HOUSE->value)
            ->exists();
    }

    /**
     * What the guest and the Concierge said between arrival and departure,
     * or null when the guest said nothing.
     *
     * @param  Collection<int, Stay>  $stays
     */
    private function transcript(Guest $guest, Collection $stays): ?string
    {
        $from = $stays->min('checked_in_at');
        $to = $stays->max('checked_out_at');

        if ($from === null || $to === null) {
            return null;
        }

        $messages = ConversationMessage::query()
            ->whereIn('conversation_id', $guest->conversations()->select('id'))
            ->whereIn('role', ['user', 'assistant'])
            ->whereRaw("TRIM(content) <> ''")
            ->whereBetween('created_at', [$from, $to])
            ->latest('created_at')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->reverse();

        if (! $messages->contains('role', 'user')) {
            return null;
        }

        return $messages
            ->map(fn (ConversationMessage $message) => ($message->role === 'user' ? '[guest] ' : '[assistant] ').trim($message->content))
            ->implode("\n");
    }

    private function prompt(Guest $guest, string $transcript): string
    {
        $notes = GuestMemory::query()
            ->where('hotel_id', $guest->hotel_id)
            ->where('guest_id', $guest->id)
            ->get()
            ->map(fn (GuestMemory $memory) => json_encode([
                'id' => $memory->id,
                'category' => $memory->category->value,
                'content' => $memory->content,
                'language' => $memory->language,
                'confidence' => $memory->confidence->value,
            ], JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        return "Notes already kept about the guest (one JSON object per line, or none):\n"
            .($notes ?: '(none)')
            ."\n\nWhat was said during the stay:\n".$transcript;
    }
}
