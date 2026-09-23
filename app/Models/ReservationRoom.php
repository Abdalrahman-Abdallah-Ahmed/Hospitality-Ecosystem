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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', ReservationRoomStatus::CANCELLED->value);
    }

    /**
     * Room ids someone is sleeping in right now, as one `room_id` column: every
     * live line with a room whose reservation's stay is in-house, plus the
     * room of any in-house stay itself. The second half keeps stays that have
     * no reservation (legacy rows, direct imports) counting as they always
     * have; the first is what makes a multi-room reservation occupy every room.
     *
     * The single source for room occupancy and the overnight dirty job. Hotel
     * scope is dropped on purpose: callers run from jobs and from cross-hotel
     * room syncs, and filter by room id themselves.
     */
    public static function inHouseRoomIds(): QueryBuilder
    {
        $fromLines = static::withoutGlobalScope('hotel')
            ->join('stays', 'stays.reservation_id', '=', 'reservation_rooms.reservation_id')
            ->whereNotNull('reservation_rooms.room_id')
            ->where('reservation_rooms.status', '!=', ReservationRoomStatus::CANCELLED->value)
            ->where('stays.status', StayStatus::IN_HOUSE->value)
            ->whereNull('stays.deleted_at')
            ->select('reservation_rooms.room_id')
            ->toBase();

        $fromStays = Stay::withoutGlobalScope('hotel')
            ->whereNotNull('room_id')
            ->where('status', StayStatus::IN_HOUSE)
            ->select('room_id')
            ->toBase();

        return $fromLines->union($fromStays);
    }
}
