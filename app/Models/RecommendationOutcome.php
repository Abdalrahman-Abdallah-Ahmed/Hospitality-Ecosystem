<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\AttributionMethod;
use App\Enums\EvidenceLevel;
use App\Enums\OutcomeType;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened after a recommendation was made — and how we know.
 *
 * Written only through RecommendationOutcomeService, which derives
 * evidence_level from attribution_method so the two can never disagree, and
 * enforces the precedence that stops a nightly guess overwriting something a
 * person observed.
 */
class RecommendationOutcome extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'recommendation_id',
        'booking_id',
        'stay_id',
        'outcome',
        'attribution_method',
        'evidence_level',
        'channel',
        'recorded_by_user_id',
        'expected_value',
        'currency',
        'minutes_to_outcome',
        'decline_reason',
        'evidence_quote',
        'confidence',
        'context',
        'type',
        'details',
        'occurred_at',
    ];

    protected $casts = [
        'outcome' => OutcomeType::class,
        'attribution_method' => AttributionMethod::class,
        'evidence_level' => EvidenceLevel::class,
        'expected_value' => 'decimal:2',
        'confidence' => 'decimal:2',
        'minutes_to_outcome' => 'integer',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * evidence_quote holds the guest's own words, so it is deliberately absent
     * from the audit trail's change set — the row itself is the record of it,
     * and copying quoted speech into a second table widens its circulation
     * without adding anything.
     *
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'recommendation_id', 'booking_id', 'stay_id', 'outcome',
            'attribution_method', 'evidence_level', 'channel',
            'recorded_by_user_id', 'expected_value', 'currency',
            'minutes_to_outcome', 'decline_reason', 'confidence', 'occurred_at',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /**
     * The commitment this recommendation produced. Present only on a BOOKED
     * outcome — that link is the conversion.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
