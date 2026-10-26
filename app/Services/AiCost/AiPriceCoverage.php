<?php

namespace App\Services\AiCost;

use App\Models\AiModelPrice;

/**
 * Whether cost can be attributed for the AI providers in use: a provider
 * with no price rows would be logged at no cost. Answers with provider names
 * only, never prices, so callers outside the cost area stay clear of cost
 * data (spec 017 FR-032).
 */
class AiPriceCoverage
{
    /**
     * @param  list<string>  $providers
     * @return list<string> the providers with no price rows
     */
    public function unpriced(array $providers): array
    {
        return array_values(array_filter(
            array_unique($providers),
            fn (string $provider) => ! AiModelPrice::where('provider', $provider)->exists(),
        ));
    }
}
