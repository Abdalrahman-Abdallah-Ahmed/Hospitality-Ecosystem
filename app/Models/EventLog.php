<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ActorKind;
use App\Enums\EvidenceLevel;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One entry in the audit trail. Written only by App\Support\Audit\EventLogger
 * (via the RecordsEvents trait); append-only, so the model refuses updates and
 * deletes the same way the transaction ledger does.
 */
class EventLog extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $table = 'event_log';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'event_type',
        'subject_type',
        'subject_id',
        'actor_type',
        'actor_id',
        'actor_kind',
        'changes',
        'context',
        'evidence_level',
        'reason',
        'occurred_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'evidence_level' => EvidenceLevel::class,
        'actor_kind' => ActorKind::class,
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('The event log is append-only; entries cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('The event log is append-only; entries cannot be deleted.');
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
