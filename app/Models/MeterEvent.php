<?php

namespace App\Models;

use App\Enums\ActorKind;
use App\Enums\MeterFeature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One append-only record that something countable happened.
 *
 * Not tenant-scoped: meter events belong to an account (a hotel group), and
 * the only things that read them are the nightly rebuild and the super-admin
 * usage report, both of which work across accounts by design.
 */
class MeterEvent extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_group_id',
        'hotel_id',
        'feature_code',
        'quantity',
        'unit',
        'source_type',
        'source_id',
        'actor_type',
        'actor_id',
        'actor_kind',
        'period_start',
        'occurred_at',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'feature_code' => MeterFeature::class,
        'actor_kind' => ActorKind::class,
        'quantity' => 'integer',
        'period_start' => 'date',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // Append-only, like the transaction ledger. A miscount is corrected
        // by rebuilding the counter from the events, never by editing one.
        static::updating(function (): void {
            throw new RuntimeException('Meter events are append-only; they cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Meter events are append-only; they cannot be deleted.');
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
