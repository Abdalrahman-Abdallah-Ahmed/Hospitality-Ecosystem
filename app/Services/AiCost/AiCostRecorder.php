<?php

namespace App\Services\AiCost;

use App\Enums\AiOperation;
use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Support\Ai\AiCostContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The one writer for AI cost. Turns a provider call into a priced,
 * append-only row.
 *
 * Callers must never let a failure here reach the user — see safely(). The
 * same rule metering follows: losing a cost row costs us a line in a report,
 * losing the AI response costs a guest their answer.
 */
class AiCostRecorder
{
    /**
     * Prices resolved during this request, keyed by provider|model|date.
     * A conversation makes several calls against the same model on the same
     * day; there is no reason to ask the database each time.
     *
     * @var array<string, AiModelPrice|false>
     */
    private array $prices = [];

    /**
     * Run a cost-logging call so that it cannot break what it is measuring.
     *
     * Every listener goes through this. If cost logging throws — a missing
     * price, a database blip — the AI response has already been produced and
     * must still reach the caller.
     */
    public function safely(callable $callback): void
    {
        try {
            $callback($this);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Record one provider call.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(
        string $agent,
        string $provider,
        string $model,
        AiOperation $operation,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedTokens = 0,
        int $toolCalls = 0,
        ?int $latencyMs = null,
        bool $succeeded = true,
        ?string $failureReason = null,
        ?int $attempt = null,
        ?Carbon $occurredAt = null,
    ): AiUsageLog {
        $occurredAt ??= now();
        $context = AiCostContext::current();

        [$cost, $isEstimated] = $this->cost(
            $provider, $model, $inputTokens, $outputTokens, $cachedTokens, $occurredAt
        );

        return AiUsageLog::create([
            'hotel_group_id' => $context['account']?->getKey(),
            'hotel_id' => $context['hotel']?->getKey(),
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation->value,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cached_tokens' => $cachedTokens,
            'tool_calls' => $toolCalls,
            'cost_usd' => $cost,
            'cost_is_estimated' => $isEstimated,
            'latency_ms' => $latencyMs,
            'succeeded' => $succeeded,
            'failure_reason' => $failureReason,
            'attempt' => $attempt ?? AiCostContext::currentAttempt(),
            'trigger_type' => $context['trigger']?->getMorphClass(),
            'trigger_id' => $context['trigger']?->getKey(),
            'trigger_kind' => $context['kind']->value,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * What this call cost, at the price that applied on the day it was made.
     *
     * Returns the cost and whether it is estimated rather than measured. The
     * two must never be summed into an unlabelled total, so the flag travels
     * with the figure from here all the way to the endpoint.
     *
     * @return array{0: float, 1: bool}
     */
    public function cost(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens,
        Carbon $on,
    ): array {
        $price = $this->priceFor($provider, $model, $on);

        if (! $price) {
            // An unpriced model is a gap, not a free call. Zero is recorded
            // because the column must hold something, and the estimated flag
            // plus the endpoint's unpriced-model list make sure the zero is
            // never read as "this cost nothing".
            Log::warning('No AI price on file for this model on this date; cost recorded as an unpriced estimate.', [
                'provider' => $provider,
                'model' => $model,
                'date' => $on->toDateString(),
            ]);

            return [0.0, true];
        }

        // Cached input is priced separately and is NOT included in
        // input_tokens — laravel/ai subtracts it before handing the usage
        // over. Where a provider offers no cached rate, the full input rate
        // applies, which may overstate slightly: the safe direction.
        $cachedRate = $price->cached_input_price_per_million ?? $price->input_price_per_million;

        $cost = (
            ($inputTokens * (float) $price->input_price_per_million)
            + ($cachedTokens * (float) $cachedRate)
            + ($outputTokens * (float) $price->output_price_per_million)
        ) / 1_000_000;

        return [round($cost, 6), false];
    }

    private function priceFor(string $provider, string $model, Carbon $on): ?AiModelPrice
    {
        $key = $provider.'|'.$model.'|'.$on->toDateString();

        if (! array_key_exists($key, $this->prices)) {
            $this->prices[$key] = AiModelPrice::effectiveOn($provider, $model, $on) ?? false;
        }

        return $this->prices[$key] ?: null;
    }
}
