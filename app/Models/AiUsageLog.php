<?php

namespace App\Models;

use App\Enums\AiOperation;
use App\Enums\AiTriggerKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One provider call, and what it cost us.
 *
 * Not tenant-scoped: a usage log belongs to an account, and the only things
 * that read these rows are the super-admin cost report and the daily alert,
 * both of which work across every account by design.
 */
class AiUsageLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_group_id',
        'hotel_id',
        'agent',
        'provider',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'tool_calls',
        'cost_usd',
        'cost_is_estimated',
        'latency_ms',
        'succeeded',
        'failure_reason',
        'attempt',
        'trigger_type',
        'trigger_id',
        'trigger_kind',
        'occurred_at',
    ];

    protected $casts = [
        'operation' => AiOperation::class,
        'trigger_kind' => AiTriggerKind::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'tool_calls' => 'integer',
        'cost_usd' => 'decimal:6',
        'cost_is_estimated' => 'boolean',
        'latency_ms' => 'integer',
        'succeeded' => 'boolean',
        'attempt' => 'integer',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only, like meter_events and transactions. What a provider
        // charged us on a given day does not change afterwards; if a figure
        // looks wrong, the price book is corrected and the total recomputed.
        static::updating(function (): void {
            throw new RuntimeException('AI usage logs are append-only; they cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('AI usage logs are append-only; they cannot be deleted.');
        });
    }

    public function hotelGroup(): BelongsTo
    {
        return $this->belongsTo(HotelGroup::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * The record that caused the call: the guest who sent the message, the
     * user who asked, the article being embedded.
     */
    public function trigger(): MorphTo
    {
        return $this->morphTo();
    }
}
