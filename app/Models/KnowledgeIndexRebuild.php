<?php

namespace App\Models;

use App\Enums\KnowledgeRebuildScope;
use App\Enums\KnowledgeRebuildStatus;
use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A platform record, read only from routes/admin.php, so it deliberately has
 * no BelongsToHotel scope: a rebuild of everything spans every hotel.
 */
class KnowledgeIndexRebuild extends Model
{
    use HasUuids;

    protected $table = 'knowledge_index_rebuilds';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scope',
        'hotel_id',
        'requested_by',
        'status',
        'total',
        'succeeded',
        'failed',
        'failures',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'scope' => KnowledgeRebuildScope::class,
        'status' => KnowledgeRebuildStatus::class,
        'failures' => 'array',
        'total' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Count one source's final outcome. Many jobs report at once, so the
     * counter is incremented in SQL rather than read-modify-written, and only
     * the update that brings the count to the total marks the rebuild
     * completed. That one, and only that one, records the completed event.
     *
     * @param  array{source_type: string, source_id: string, title: ?string, reason: string}|null  $failure
     */
    public function recordOutcome(bool $succeeded, ?array $failure = null): void
    {
        $column = $succeeded ? 'succeeded' : 'failed';

        DB::update(
            "UPDATE knowledge_index_rebuilds SET {$column} = {$column} + 1, "
            .'failures = CASE WHEN ?::jsonb IS NULL THEN failures ELSE failures || ?::jsonb END, '
            .'updated_at = now() WHERE id = ? AND succeeded + failed < total',
            [
                $failure === null ? null : json_encode([$failure]),
                $failure === null ? null : json_encode([$failure]),
                $this->id,
            ],
        );

        $completed = DB::update(
            "UPDATE knowledge_index_rebuilds SET status = 'completed', finished_at = now(), updated_at = now() "
            ."WHERE id = ? AND status = 'running' AND succeeded + failed >= total",
            [$this->id],
        );

        $this->refresh();

        if ($completed === 1) {
            EventLogger::record($this, 'completed', [
                'succeeded' => ['to' => $this->succeeded],
                'failed' => ['to' => $this->failed],
            ]);
        }
    }
}
