<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomTypes;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'room_number',
        'room_type',
        'floor',
        'status',
        'housekeeping_status',
    ];

    protected $casts = [
        'room_type' => RoomTypes::class,
        'housekeeping_status' => HousekeepingStatusesEnum::class,
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
