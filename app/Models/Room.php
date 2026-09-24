<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use BelongsToHotel, Filterable, HasFactory, HasUuids, RecordsEvents;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'room_number',
        'room_type_id',
        'floor',
        'status',
        'housekeeping_status',
    ];

    protected $casts = [
        'housekeeping_status' => HousekeepingStatusesEnum::class,
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return ['room_number', 'room_type_id', 'floor', 'status', 'housekeeping_status'];
    }

    /**
     * Whether the room is out of service and must not take a guest.
     *
     * SPEC-003: until the status split, that is a maintenance room status or
     * a blocked housekeeping status (both become out_of_order under D5).
     */
    public function isOutOfOrder(): bool
    {
        $status = $this->status instanceof RoomStatusesEnum ? $this->status->value : $this->status;

        return in_array($status, array_column(RoomStatusesEnum::outOfOrder(), 'value'), true)
            || $this->housekeeping_status === HousekeepingStatusesEnum::BLOCKED;
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }
}
