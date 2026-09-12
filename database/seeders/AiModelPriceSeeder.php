<?php

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Opening balances for the price book.
 *
 * The **Gemini** rows are real published rates, supplied by the owner. Gemini
 * is what this deployment actually bills against — `AI_DEFAULT_PROVIDER` and
 * `default_for_embeddings` both resolve there — so those are the numbers that
 * decide whether the cost report means anything.
 *
 * ⚠ The **OpenAI, Anthropic and Cohere** rows are still PLACEHOLDERS. They
 * exist so a provider switch does not immediately produce unpriced calls, and
 * they must be replaced with published rates before any figure computed from
 * them is believed. The spread across the providers in config/ai.php is more
 * than thirtyfold, so a wrong rate does not produce a slightly wrong report,
 * it produces a confidently wrong one.
 *
 * To change a price, never edit a row — supersede it:
 *
 *     AiModelPrice::supersede('gemini', 'gemini-3.6-flash', 1.50, 9.00, 0.15, now());
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
     * Per million tokens, in USD:
     * [provider, model, input, output, cached input, effective_to].
     *
     * A non-null effective_to is a price with a KNOWN END — a promotional
     * rate. See runsOut() for why that end is recorded rather than ignored.
     */
    private const PRICES = [
        // --- Gemini: real published rates -------------------------------

        // Promotional through 31 Dec 2026. The standard rate that replaces
        // these has not been published, so the rows are closed on that date
        // rather than guessed forward.
        ['gemini', 'gemini-3.8-flash', 0.7500, 3.7500, 0.0750, '2026-12-31'],
        ['gemini', 'gemini-3.7-flash', 0.7500, 3.7500, 0.0750, '2026-12-31'],
        ['gemini', 'gemini-3.6-flash', 0.7500, 3.7500, 0.0750, '2026-12-31'],

        // Standard rates.
        ['gemini', 'gemini-3.5-flash', 1.5000, 9.0000, 0.1500, null],
        ['gemini', 'gemini-3.5-flash-lite', 0.3000, 2.5000, 0.0300, null],
        ['gemini', 'gemini-3.1-flash-lite', 0.2500, 1.5000, 0.0250, null],
        ['gemini', 'gemini-3-flash-preview', 0.5000, 3.0000, 0.0500, null],
        ['gemini', 'gemini-3.1-pro-preview', 2.0000, 12.0000, 0.2000, null],

        ['gemini', 'gemini-2.5-pro', 1.2500, 10.0000, 0.1250, null],
        ['gemini', 'gemini-2.5-flash', 0.3000, 2.5000, 0.0300, null],
        ['gemini', 'gemini-2.5-flash-lite', 0.1000, 0.4000, 0.0100, null],

        // Embeddings — input only; there are no output tokens.
        ['gemini', 'gemini-embedding-001', 0.1500, 0.0000, null, null],
        ['gemini', 'gemini-embedding-2', 0.2000, 0.0000, null, null],

        // --- Placeholders: NOT published rates --------------------------

        ['openai', 'gpt-5.4', 1.2500, 10.0000, 0.1250, null],
        ['openai', 'gpt-5.4-nano', 0.0500, 0.4000, 0.0050, null],
        ['openai', 'gpt-5.4-pro', 15.0000, 120.0000, 1.5000, null],
        ['openai', 'gpt-4o', 2.5000, 10.0000, 1.2500, null],
        ['openai', 'gpt-4o-mini', 0.1500, 0.6000, 0.0750, null],
        ['openai', 'text-embedding-3-small', 0.0200, 0.0000, null, null],
        ['openai', 'text-embedding-3-large', 0.1300, 0.0000, null, null],

        ['anthropic', 'claude-sonnet-5', 3.0000, 15.0000, 0.3000, null],
        ['anthropic', 'claude-opus-5', 15.0000, 75.0000, 1.5000, null],
        ['anthropic', 'claude-haiku-4-5-20251001', 1.0000, 5.0000, 0.1000, null],

        // Reranking — priced per search by the provider, not per token.
        // Recorded at zero so the row exists and the model is not reported as
        // unpriced; rerank spend is not token-derived and needs its own
        // treatment if it ever becomes material.
        ['cohere', 'rerank-v3.5', 0.0000, 0.0000, null, null],
    ];

    public function run(): void
    {
        // Backdated so that usage logged before this seeder ran still finds a
        // price. Note that AiCostRecorder computes and STORES cost when the
        // call is logged, using the price effective on that day — so seeding
        // or correcting a price does not retroactively change rows already
        // written. Historical accuracy comes from never having the wrong
        // price on file in the first place.
        $effectiveFrom = Carbon::parse('2026-01-01');

        foreach (self::PRICES as [$provider, $model, $input, $output, $cached, $until]) {
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
                'effective_to' => $until ? Carbon::parse($until) : null,
            ]);
        }
    }

    /**
     * Why a promotional rate is closed on its end date rather than left open.
     *
     * Leaving it open would mean that on 1 January 2027 every call keeps
     * being costed at a discount that no longer exists, and the report would
     * quietly understate what we are paying — a confidently wrong number, and
     * the one failure this whole phase is built to prevent.
     *
     * Closing it means those calls become UNPRICED instead: logged with
     * cost_is_estimated = true, named in the `unpriced_models` block of
     * /api/admin/ai-cost, and accompanied by a logged warning. That is a
     * visible gap demanding an action, which is the right kind of wrong.
     *
     * So: before 1 January 2027, supersede these three with whatever Google
     * publishes as the standard rate.
     */
    public static function runsOut(): array
    {
        return ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash'];
    }
}
