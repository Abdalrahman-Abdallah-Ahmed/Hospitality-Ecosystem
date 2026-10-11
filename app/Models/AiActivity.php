<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\AiActivityCategory;
use App\Enums\AiActivityResult;
use App\Enums\AiAgentName;
use App\Enums\AiTriggerKind;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One thing the AI did for a hotel: a run, a write it made or attempted, or
 * a pitch or proactive message (SPEC-083). Written only by
 * App\Support\Ai\AiActivityRecorder.
 *
 * Append-only like EventLog. A run that learns its conversation id late
 * back-fills it on its own rows with a bulk query update (AiRun::close()),
 * which deliberately bypasses this guard; through the model, nothing changes.
 */
class AiActivity extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'ai_run_id',
        'category',
        'agent',
        'tool',
        'action',
        'result',
        'reason',
        'target_type',
        'target_id',
        'changes_count',
        'conversation_id',
        'acted_for_type',
        'acted_for_id',
        'trigger_kind',
        'usage',
        'occurred_at',
    ];

    protected $casts = [
        'category' => AiActivityCategory::class,
        'agent' => AiAgentName::class,
        'result' => AiActivityResult::class,
        'trigger_kind' => AiTriggerKind::class,
        'changes_count' => 'integer',
        'usage' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('AI activity entries are append-only; they cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('AI activity entries are append-only; they cannot be deleted.');
        });
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function actedFor(): MorphTo
    {
        return $this->morphTo();
    }
}
