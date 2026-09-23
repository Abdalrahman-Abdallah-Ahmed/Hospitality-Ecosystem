<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ReservationRoomStatus;
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
            'guest_id', 'arrival_date', 'departure_date', 'status',
            'adults', 'children', 'source', 'special_requests',
            'reservation_value', 'currency',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Every booked room unit, cancelled ones included (history). Ordered so
     * the "first" line is stable: see primaryRoomId().
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The physical room the reservation's single stay points at until
     * SPEC-023 gives every line its own stay: the room of the first live line
     * that has one.
     */
    public function primaryRoomId(): ?string
    {
        return $this->reservationRooms()
            ->active()
            ->whereNotNull('room_id')
            ->value('room_id');
    }

    /**
     * The rooms as the AI tools present them: every line with its type, room
     * number (null while unassigned) and status, plus a per-type summary of
     * the live ones ("2 × Deluxe"). Reads the loaded lines when present.
     *
     * @return array{rooms: array<int, array{room_type: ?string, room_number: ?string, status: string}>, room_summary: array<int, string>}
     */
    public function roomsForAi(): array
    {
        $lines = $this->relationLoaded('reservationRooms')
            ? $this->reservationRooms
            : $this->reservationRooms()->with(['roomType', 'room'])->get();

        return [
            'rooms' => $lines->map(fn (ReservationRoom $line) => [
                'room_type' => $line->roomType?->name,
                'room_number' => $line->room?->room_number,
                'status' => $line->status->value,
            ])->values()->all(),
            'room_summary' => $lines
                ->reject(fn (ReservationRoom $line) => $line->status === ReservationRoomStatus::CANCELLED)
                ->groupBy(fn (ReservationRoom $line) => $line->roomType?->name)
                ->map(fn ($group, $name) => "{$group->count()} × {$name}")
                ->values()
                ->all(),
        ];
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
