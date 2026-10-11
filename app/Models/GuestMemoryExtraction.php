<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\GuestMemoryExtractionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether one guest's memories for one reservation have been extracted, and
 * how it ended. Unique per guest and reservation, so extraction runs once.
 * Status, attempts and retry time are written only by ExtractGuestMemoriesJob
 * and the super-admin re-run.
 */
class GuestMemoryExtraction extends Model
{
    use BelongsToHotel, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'reservation_id',
        'status',
        'attempts',
        'retry_at',
        'memories_written',
        'ai_run_id',
        'last_error',
    ];

    protected $casts = [
        'status' => GuestMemoryExtractionStatus::class,
        'attempts' => 'integer',
        'memories_written' => 'integer',
        'retry_at' => 'datetime',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
