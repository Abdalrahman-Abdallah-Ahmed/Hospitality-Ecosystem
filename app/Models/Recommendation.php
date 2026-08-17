<?php

namespace App\Models;

use App\Enums\RecommendationStatus;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    use Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'reservation_id',
        'activity_id',
        'hotel_id',
        'reason',
        'predicted_confidence',
        'guest_confidence',
        'priority',
        'status',
        'recommended_at',
        'accepted_at',
        'rejected_at',
        'dismissed_at',
    ];

    protected $casts = [
        'predicted_confidence' => 'decimal:2',
        'guest_confidence' => 'decimal:2',
        'priority' => 'integer',
        'status' => RecommendationStatus::class,
        'recommended_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(RecommendationOutcome::class);
    }
}
