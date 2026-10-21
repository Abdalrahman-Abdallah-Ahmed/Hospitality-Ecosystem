<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReservationGuest extends Model
{
    use BelongsToHotel, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['hotel_id', 'reservation_id', 'guest_id', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function eventLoggedAttributes(): array
    {
        return ['reservation_id', 'guest_id', 'is_primary'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
