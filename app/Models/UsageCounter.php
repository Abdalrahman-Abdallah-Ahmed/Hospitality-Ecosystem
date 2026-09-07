<?php

namespace App\Models;

use App\Enums\MeterFeature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached period total. Derived, disposable, and never the answer to
 * "what happened" — that is meter_events.
 */
class UsageCounter extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_group_id',
        'feature_code',
        'period_start',
        'used',
        'recomputed_at',
    ];

    protected $casts = [
        'feature_code' => MeterFeature::class,
        'period_start' => 'date',
        'used' => 'integer',
        'recomputed_at' => 'datetime',
    ];

    public function hotelGroup(): BelongsTo
    {
        return $this->belongsTo(HotelGroup::class);
    }
}
