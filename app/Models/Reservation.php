<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ReservationStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

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

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'guest_id', 'room_id', 'arrival_date', 'departure_date', 'status',
            'adults', 'children', 'source', 'special_requests',
            'reservation_value', 'currency',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
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
