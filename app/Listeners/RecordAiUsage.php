<?php

namespace App\Listeners;

use App\Enums\AiOperation;
use App\Services\AiCost\AiCostRecorder;
use App\Support\Ai\AiCostContext;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;

/**
 * Central capture for AI cost.
 *
 * This listens to laravel/ai's own events rather than adding logging inside
 * each agent. That is the whole point: the fifth agent someone writes next
 * year is logged the day it is written, because it goes through the same
 * package code these events come from. There is nothing to remember to add,
 * and so nothing to forget.
 *
 * Every handler is wrapped in safely(): a cost row is worth less than the
 * response it is describing, and must never be the reason one is lost.
 */
class RecordAiUsage
{
    /**
     * Start times by invocation id, so latency can be measured across the
     * pair of events that bracket a call.
     *
     * @var array<string, float>
     */
    private array $startedAt = [];

    public function __construct(private readonly AiCostRecorder $recorder) {}

    /**
     * A prompt is about to go to a provider. Nothing is written yet — this
     * only starts the clock and counts the attempt.
     */
    public function promptingAgent(PromptingAgent $event): void
    {
        $this->startedAt[$event->invocationId] = microtime(true);

        AiCostContext::nextAttempt();
    }

    public function generatingEmbeddings(GeneratingEmbeddings $event): void
    {
        $this->startedAt[$event->invocationId] = microtime(true);
    }

    /**
     * A completed agent call. Covers streamed responses too, since
     * AgentStreamed extends AgentPrompted.
     */
    public function agentPrompted(AgentPrompted $event): void
    {
        $this->recorder->safely(function (AiCostRecorder $recorder) use ($event) {
            $usage = $event->response->usage;
            $meta = $event->response->meta;

            $recorder->record(
                agent: class_basename($event->prompt->agent),
                provider: $meta->provider ?? $event->prompt->provider()->name(),
                model: $meta->model ?? $event->prompt->model,
                operation: AiOperation::CHAT,
                // promptTokens already excludes anything served from cache;
                // cache WRITES are folded in here and billed at the full input
                // rate. Providers that charge a premium for a cache write
                // (Anthropic, at 1.25x) are therefore slightly understated —
                // and are zero today, since nothing enables prompt caching.
                inputTokens: $usage->promptTokens + $usage->cacheWriteInputTokens,
                // Reasoning tokens are a SUBSET of completion tokens, not an
                // addition to them. Adding them would bill every reasoning
                // token twice.
                outputTokens: $usage->completionTokens,
                cachedTokens: $usage->cacheReadInputTokens,
                toolCalls: $event->response->toolCalls->count(),
                latencyMs: $this->elapsed($event->invocationId),
            );
        });
    }

    /**
     * A failed attempt that fell over to the next provider.
     *
     * A call that retried three times cost four calls. The provider may well
     * have charged for the tokens it processed before failing, but it reports
     * nothing we can read, so the row is written with zero tokens and marked
     * estimated — a known-incomplete fact, recorded as such, rather than a
     * silent omission that makes retries look free.
     */
    public function agentFailedOver(AgentFailedOver $event): void
    {
        $this->recorder->safely(function (AiCostRecorder $recorder) use ($event) {
            $recorder->record(
                agent: class_basename($event->agent),
                provider: $event->provider->name(),
                model: $event->model,
                operation: AiOperation::CHAT,
                succeeded: false,
                failureReason: class_basename($event->exception).': '.$event->exception->getMessage(),
            );
        });
    }

    /**
     * Embeddings: pure cost with no visible output, which is exactly why they
     * are the line everyone forgets. The knowledge-base sync re-embeds an
     * entire article every time someone edits a typo in it.
     */
    public function embeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->recorder->safely(function (AiCostRecorder $recorder) use ($event) {
            $recorder->record(
                agent: 'Embeddings',
                provider: $event->provider->name(),
                model: $event->model,
                operation: AiOperation::EMBEDDING,
                inputTokens: $event->response->tokens,
                latencyMs: $this->elapsed($event->invocationId),
            );
        });
    }

    public function reranked(Reranked $event): void
    {
        $this->recorder->safely(function (AiCostRecorder $recorder) use ($event) {
            $recorder->record(
                agent: 'Reranking',
                provider: $event->provider->name(),
                model: $event->model,
                operation: AiOperation::RERANK,
                latencyMs: $this->elapsed($event->invocationId),
            );
        });
    }

    /**
     * How long the call took, in milliseconds, discarding the start time so
     * a long-lived worker does not accumulate them.
     */
    private function elapsed(string $invocationId): ?int
    {
        $start = $this->startedAt[$invocationId] ?? null;

        unset($this->startedAt[$invocationId]);

        return $start === null ? null : (int) round((microtime(true) - $start) * 1000);
    }
}
