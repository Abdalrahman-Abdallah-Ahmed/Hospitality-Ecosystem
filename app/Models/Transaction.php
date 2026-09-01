<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\EvidenceLevel;
use App\Enums\TransactionSource;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One line of the commercial ledger. Append-only by design — see the
 * migration. Corrections go through TransactionService::reverse(), which
 * writes a new negated row; nothing edits or deletes an existing one, and
 * the model enforces that rather than trusting every caller to remember.
 */
class Transaction extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'stay_id',
        'room_id',
        'activity_id',
        'item_name',
        'revenue_center',
        'department',
        'quantity',
        'unit_price',
        'line_total',
        'discount_amount',
        'currency',
        'transacted_at',
        'business_date',
        'seller_reference',
        'sold_by_user_id',
        'source_system',
        'external_reference',
        'evidence_level',
        'reverses_transaction_id',
        'raw_payload',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'transacted_at' => 'datetime',
        'business_date' => 'date',
        'raw_payload' => 'array',
        'evidence_level' => EvidenceLevel::class,
        'source_system' => TransactionSource::class,
    ];

    protected static function booted(): void
    {
        // The ledger is append-only. A wrong entry is corrected with a
        // reversing row (a fresh insert, unaffected by these guards), never
        // by editing or removing the original.
        static::updating(function (): void {
            throw new RuntimeException('Transactions are append-only; write a reversal instead of updating one.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Transactions are append-only; write a reversal instead of deleting one.');
        });
    }

    /**
     * The ledger is append-only, so in practice only the `created` event ever
     * fires; the list is kept complete anyway.
     *
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'guest_id', 'stay_id', 'room_id', 'activity_id', 'item_name',
            'revenue_center', 'department', 'quantity', 'unit_price',
            'line_total', 'discount_amount', 'currency', 'transacted_at',
            'business_date', 'source_system', 'external_reference',
            'evidence_level', 'reverses_transaction_id',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_transaction_id');
    }

    /**
     * Revenue is only ever summed within a single currency — Phase 1 does no
     * FX conversion. Callers group by currency and scope with this first.
     */
    public function scopeInCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }
}
