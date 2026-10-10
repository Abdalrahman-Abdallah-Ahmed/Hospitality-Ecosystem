<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ProactiveMessageStatus;
use App\Enums\ProactiveSkipReason;
use App\Enums\ProactiveTrigger;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message the Concierge may send a guest without being asked (SPEC-073),
 * and how it ended.
 *
 * Created by EvaluateProactiveTriggersJob, at most once per guest, trigger and
 * event (the unique key). Everything after scheduling — status, reason,
 * attempts, the text sent — is written only by SendProactiveMessageJob, so
 * those columns are not fillable. It is itself the record, so it neither
 * soft-deletes nor writes to the audit trail.
 */
class ProactiveMessage extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'reservation_id',
        'stay_id',
        'trigger',
        'event_key',
        'booking_id',
        'recommendation_id',
        'due_at',
        'valid_until',
    ];

    protected $casts = [
        'trigger' => ProactiveTrigger::class,
        'status' => ProactiveMessageStatus::class,
        'reason' => ProactiveSkipReason::class,
        'due_at' => 'datetime',
        'valid_until' => 'datetime',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }
}
