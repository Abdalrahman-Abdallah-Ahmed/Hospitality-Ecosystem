<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one data row of an import (FR-026). Warnings are
 * `{code, message}` items; see ImportWarning for the codes.
 */
class ReservationImportRow extends Model
{
    use BelongsToHotel, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'reservation_import_id',
        'row_number',
        'external_reference',
        'outcome',
        'stage',
        'fields',
        'reason',
        'warnings',
        'reservation_id',
        'draft_id',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'outcome' => ImportRowOutcome::class,
        'stage' => ImportStage::class,
        'fields' => 'array',
        'warnings' => 'array',
    ];

    public function reservationImport(): BelongsTo
    {
        return $this->belongsTo(ReservationImport::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
