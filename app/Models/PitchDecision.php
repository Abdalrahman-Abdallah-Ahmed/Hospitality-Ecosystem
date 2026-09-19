<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\PitchOpening;
use App\Enums\PitchResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Why one guest turn was or was not pitched.
 *
 * It is itself the record, so it neither soft-deletes nor writes to the audit
 * trail. The decision is written once, before the agent runs, and never
 * changes. Only the completion columns may be written afterwards, and only
 * once — a decision rewritten after the fact explains nothing.
 */
class PitchDecision extends Model
{
    use BelongsToHotel, HasUuids;

    /**
     * The only columns an update may touch, and only while completed_at is
     * still null.
     */
    public const COMPLETION_COLUMNS = ['result', 'recommendation_id', 'chosen_rank', 'mention_verified', 'completed_at'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'stay_id',
        'conversation_id',
        'eligible',
        'gates',
        'classifier_ran',
        'complaint',
        'opening',
        'explicit_request',
        'opening_quote',
        'interest_category_id',
        'candidates',
        'signals',
        'rules_version',
        'decided_at',
        'result',
        'recommendation_id',
        'chosen_rank',
        'mention_verified',
        'completed_at',
    ];

    protected $casts = [
        'eligible' => 'boolean',
        'gates' => 'array',
        'classifier_ran' => 'boolean',
        'complaint' => 'boolean',
        'opening' => PitchOpening::class,
        'explicit_request' => 'boolean',
        'candidates' => 'array',
        'signals' => 'array',
        'decided_at' => 'datetime',
        'result' => PitchResult::class,
        'chosen_rank' => 'integer',
        'mention_verified' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PitchDecision $decision): void {
            $changed = array_diff(array_keys($decision->getDirty()), [...self::COMPLETION_COLUMNS, 'updated_at']);

            if ($changed !== []) {
                throw new RuntimeException('A pitch decision is written once; its decision columns cannot change.');
            }

            if ($decision->getOriginal('completed_at') !== null) {
                throw new RuntimeException('This pitch decision is already complete.');
            }
        });
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }
}
