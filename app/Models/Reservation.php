<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'room_id',
        'reservation_id',
        'arrival_date',
        'departure_date',
        'status',
        'adults',
        'children',
        'source',
        'special_requests',
        'reservation_value',
        'currency',
    ];

    protected $casts = [
        'arrival_date' => 'date',
        'departure_date' => 'date',
        'status' => ReservationStatus::class,
        'reservation_value' => 'decimal:2',
        'adults' => 'integer',
        'children' => 'integer',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', ReservationStatus::CONFIRMED);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereDate('arrival_date', '>=', now()->toDateString());
    }
}
