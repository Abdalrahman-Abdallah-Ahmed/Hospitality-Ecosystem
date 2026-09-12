<?php

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Opening balances for the price book.
 *
 * ⚠ THESE FIGURES ARE PLACEHOLDERS AND MUST BE REPLACED WITH THE PROVIDER'S
 * PUBLISHED RATES BEFORE ANY COST REPORT IS BELIEVED. They are here so the
 * pipeline has something to compute against, not because they are correct.
 *
 * The price spread across the providers in config/ai.php is more than
 * thirty-fold, so a wrong rate here does not produce a slightly wrong report,
 * it produces a confidently wrong one.
 *
 * To correct or update a price, never edit a row — supersede it:
 *
 *     AiModelPrice::supersede('openai', 'gpt-5.4', 1.25, 10.00, 0.125, now());
 *
 * That closes the old row the day before the new one starts, so every cost
 * already reported against the old price still recomputes to the same figure.
 *
 * A model with no row here is not free: it is unpriced. Its calls are logged
 * with an estimated zero cost and listed explicitly by /api/admin/ai-cost, so
 * the gap shows up as a gap.
 */
class AiModelPriceSeeder extends Seeder
{
    /**
     * Per million tokens, in USD: [provider, model, input, output, cached input].
     */
    private const PLACEHOLDER_PRICES = [
        // Text — the app's default provider is openai (config/ai.php).
        ['openai', 'gpt-5.4', 1.2500, 10.0000, 0.1250],
        ['openai', 'gpt-5.4-nano', 0.0500, 0.4000, 0.0050],
        ['openai', 'gpt-5.4-pro', 15.0000, 120.0000, 1.5000],
        ['openai', 'gpt-4o', 2.5000, 10.0000, 1.2500],
        ['openai', 'gpt-4o-mini', 0.1500, 0.6000, 0.0750],

        ['anthropic', 'claude-sonnet-5', 3.0000, 15.0000, 0.3000],
        ['anthropic', 'claude-opus-5', 15.0000, 75.0000, 1.5000],
        ['anthropic', 'claude-haiku-4-5-20251001', 1.0000, 5.0000, 0.1000],

        ['gemini', 'gemini-3.6-flash', 0.3000, 2.5000, 0.0750],
        ['gemini', 'gemini-3.5-flash-lite', 0.1000, 0.4000, 0.0250],

        // Embeddings — the app's default embeddings provider is gemini.
        ['gemini', 'gemini-embedding-2', 0.1500, 0.0000, null],
        ['openai', 'text-embedding-3-small', 0.0200, 0.0000, null],
        ['openai', 'text-embedding-3-large', 0.1300, 0.0000, null],

        // Reranking — priced per search by the provider, not per token.
        // Recorded at zero so the row exists and the model is not reported as
        // unpriced; rerank spend is not token-derived and needs its own
        // treatment if it ever becomes material.
        ['cohere', 'rerank-v3.5', 0.0000, 0.0000, null],
    ];

    public function run(): void
    {
        // Backdated so that any usage already logged before this seeder ran
        // still finds a price. Costs are computed at read time from the row
        // effective on the day of the call.
        $effectiveFrom = Carbon::parse('2026-01-01');

        foreach (self::PLACEHOLDER_PRICES as [$provider, $model, $input, $output, $cached]) {
            if (AiModelPrice::effectiveOn($provider, $model, $effectiveFrom)) {
                continue;   // already priced; superseding is a deliberate act
            }

            AiModelPrice::create([
                'provider' => $provider,
                'model' => $model,
                'input_price_per_million' => $input,
                'output_price_per_million' => $output,
                'cached_input_price_per_million' => $cached,
                'currency' => 'USD',
                'effective_from' => $effectiveFrom,
            ]);
        }
    }
}
