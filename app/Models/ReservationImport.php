<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ImportDateOrder;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reservation import upload (SPEC-100/101): its status, progress, totals
 * and the report-level lists. Its row results are the reconciliation report.
 *
 * Not soft-deletable: an import is a record of what happened, never edited
 * by clients, and goes only with its hotel.
 */
class ReservationImport extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    public array $searchable = ['file_name'];

    protected $fillable = [
        'hotel_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'dry_run',
        'date_order',
        'status',
        'stage',
        'total_rows',
        'processed_rows',
        'imported',
        'skipped',
        'duplicate',
        'invalid',
        'unresolved',
        'created_rooms',
        'created_room_types',
        'unrecognized_columns',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'date_order' => ImportDateOrder::class,
        'status' => ImportStatus::class,
        'stage' => ImportStage::class,
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'imported' => 'integer',
        'skipped' => 'integer',
        'duplicate' => 'integer',
        'invalid' => 'integer',
        'unresolved' => 'integer',
        'created_rooms' => 'array',
        'created_room_types' => 'array',
        'unrecognized_columns' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ReservationImportRow::class);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }
}
