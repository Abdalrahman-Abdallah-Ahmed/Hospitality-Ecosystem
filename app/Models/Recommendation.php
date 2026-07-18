<?php

namespace App\Models;

use App\Enums\RecommendationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'booking_id',
        'service_id',
        'reason',
        'confidence',
        'priority',
        'status',
        'recommended_at',
        'accepted_at',
        'rejected_at',
        'dismissed_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'priority' => 'integer',
        'status' => RecommendationStatus::class,
        'recommended_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(RecommendationOutcome::class);
    }
}
