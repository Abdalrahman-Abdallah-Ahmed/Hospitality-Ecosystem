<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded reservation file waiting to be mapped to the import's columns
 * (016). It keeps the stored file and the head of the first sheet, from
 * which the mapping screen is built for any header row.
 *
 * Not soft-deletable: its file and sample rows hold guest data that must
 * really go when it is discarded or expires (research R7).
 */
class ReservationImportPreview extends Model
{
    use BelongsToHotel, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'head_rows',
        'last_row',
        'expires_at',
    ];

    protected $casts = [
        'head_rows' => 'array',
        'last_row' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ReservationImport::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
