<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ReservationRoomStatus;
use App\Enums\StayStatus;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One booked room unit on a reservation: "2 × Deluxe" is two of these. It
 * names the room type booked and, optionally, the physical room the guest
 * will occupy. It has no dates of its own; every line shares the
 * reservation's arrival and departure.
 */
class ReservationRoom extends Model
{
    use BelongsToHotel, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'reserved',
        'cancelled_with_reservation' => false,
    ];

    protected $fillable = [
        'hotel_id',
        'reservation_id',
        'room_type_id',
        'room_id',
        'status',
        // True when the line was cancelled because its whole reservation was,
        // so bringing the reservation back restores it.
        'cancelled_with_reservation',
    ];

    protected $casts = [
        'status' => ReservationRoomStatus::class,
        'cancelled_with_reservation' => 'boolean',
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return ['room_type_id', 'room_id', 'status'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * The guest presence for this line (SPEC-023): exactly one per line.
     */
    public function stay(): HasOne
    {
        return $this->hasOne(Stay::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', ReservationRoomStatus::CANCELLED->value);
    }

    /**
     * Room ids someone is sleeping in right now, as one `room_id` column: the
     * room of every in-house stay. Every stay carries its line's room (one
     * stay per line), and legacy stays without a reservation carry their own.
     *
     * The single source for room occupancy and the overnight dirty job. Hotel
     * scope is dropped on purpose: callers run from jobs and from cross-hotel
     * room syncs, and filter by room id themselves.
     */
    public static function inHouseRoomIds(): QueryBuilder
    {
        return Stay::withoutGlobalScope('hotel')
            ->whereNotNull('room_id')
            ->where('status', StayStatus::IN_HOUSE)
            ->select('room_id')
            ->toBase();
    }
}
