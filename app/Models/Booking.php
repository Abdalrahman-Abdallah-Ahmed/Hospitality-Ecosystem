<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\EvidenceLevel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A commitment: a slot is held and the guest is expected.
 *
 * This is the event a recommendation is trying to cause. Whether money
 * follows — and when, and whether at all — is a separate fact recorded in the
 * transaction ledger. Never derive one from the other; see BookingService.
 */
class Booking extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'stay_id',
        'activity_id',
        'recommendation_id',
        'reference',
        'item_name',
        'status',
        'scheduled_for',
        'pax',
        'charge_model',
        'expected_value',
        'currency',
        'origin',
        'channel',
        'created_by_user_id',
        'confirmed_at',
        'realised_at',
        'cancelled_at',
        'cancellation_reason',
        'evidence_level',
        'context',
    ];

    protected $casts = [
        'status' => BookingStatus::class,
        'charge_model' => ChargeModel::class,
        'origin' => BookingOrigin::class,
        'evidence_level' => EvidenceLevel::class,
        'scheduled_for' => 'datetime',
        'confirmed_at' => 'datetime',
        'realised_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pax' => 'integer',
        'expected_value' => 'decimal:2',
        'context' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'guest_id', 'stay_id', 'activity_id', 'recommendation_id',
            'item_name', 'status', 'scheduled_for', 'pax', 'charge_model',
            'expected_value', 'currency', 'origin', 'channel', 'confirmed_at',
            'realised_at', 'cancelled_at', 'cancellation_reason',
        ];
    }

    /**
     * A status change is a lifecycle event, not a generic edit — surface it as
     * booking.confirmed / realised / no_show / cancelled so the audit trail
     * reads like what actually happened.
     */
    public function eventVerbFor(string $verb): string
    {
        if ($verb !== 'updated' || ! array_key_exists('status', $this->getChanges())) {
            return $verb;
        }

        return match ($this->status) {
            BookingStatus::PENDING => 'pending',
            BookingStatus::CONFIRMED => 'confirmed',
            BookingStatus::REALISED => 'realised',
            BookingStatus::NO_SHOW => 'no_show',
            BookingStatus::CANCELLED => 'cancelled',
        };
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Settlement, linked loosely and only by direct reference. A booking with
     * no transactions is not a failed booking — on an INCLUDED charge model it
     * is the expected case.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The recommendation outcome this booking was credited to, if any. Used
     * by the matcher to skip bookings that are already spoken for, so no
     * booking is ever counted for two recommendations.
     */
    public function outcomeLink(): HasOne
    {
        return $this->hasOne(RecommendationOutcome::class);
    }
}
