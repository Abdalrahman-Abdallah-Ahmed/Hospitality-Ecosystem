<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The dated price book. One row per provider/model/price period.
 */
class AiModelPrice extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'provider',
        'model',
        'input_price_per_million',
        'output_price_per_million',
        'cached_input_price_per_million',
        'currency',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'input_price_per_million' => 'decimal:4',
        'output_price_per_million' => 'decimal:4',
        'cached_input_price_per_million' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /**
     * The price that applied to this model on this date — not today's price.
     *
     * A cost report for August must use August's rates, or it produces a
     * number that cannot be reconciled against the invoice that was actually
     * paid. Returns null when the model has no price on that date, which the
     * caller must surface rather than treat as free.
     */
    public static function effectiveOn(string $provider, string $model, Carbon $date): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Record a new price for a model, closing the previous one the day
     * before it starts.
     *
     * Supersession, not overwriting: the old row stays exactly as it was, so
     * every cost already computed against it still recomputes to the same
     * figure.
     */
    public static function supersede(
        string $provider,
        string $model,
        float $inputPerMillion,
        float $outputPerMillion,
        ?float $cachedInputPerMillion,
        Carbon $effectiveFrom,
    ): self {
        $current = static::effectiveOn($provider, $model, $effectiveFrom);

        if ($current && $current->effective_from->gte($effectiveFrom)) {
            // Rewriting history rather than extending it. Refused: an older
            // price cannot be replaced without invalidating every cost figure
            // already reported against it.
            Log::warning('Refused to supersede an AI model price with one that starts no later than it.', [
                'provider' => $provider,
                'model' => $model,
                'existing_effective_from' => $current->effective_from->toDateString(),
                'attempted_effective_from' => $effectiveFrom->toDateString(),
            ]);

            return $current;
        }

        $current?->forceFill([
            'effective_to' => $effectiveFrom->copy()->subDay(),
        ])->save();

        return static::create([
            'provider' => $provider,
            'model' => $model,
            'input_price_per_million' => $inputPerMillion,
            'output_price_per_million' => $outputPerMillion,
            'cached_input_price_per_million' => $cachedInputPerMillion,
            'currency' => 'USD',
            'effective_from' => $effectiveFrom,
        ]);
    }
}
