<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\GuestMemoryCategory;
use App\Enums\GuestMemoryConfidence;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable fact about a guest at one hotel (SPEC-084): a hint for the
 * Concierge, never PMS data. Written only by GuestMemoryService from an
 * extraction; there is no create or edit API.
 *
 * No soft deletes: deleting a memory, or its guest, removes it for good.
 */
class GuestMemory extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'category',
        'content',
        'language',
        'confidence',
        'source_stay_id',
        'extracted_at',
    ];

    protected $casts = [
        'category' => GuestMemoryCategory::class,
        'confidence' => GuestMemoryConfidence::class,
        'extracted_at' => 'datetime',
    ];

    /**
     * `content` is deliberately absent: a memory is protected guest data,
     * and the audit trail must never carry it (FR-024).
     *
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return ['category', 'confidence', 'language', 'source_stay_id'];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function sourceStay(): BelongsTo
    {
        return $this->belongsTo(Stay::class, 'source_stay_id');
    }
}
