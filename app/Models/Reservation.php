<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ReservationRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
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
     * The room of the first live line that has one. The Concierge's guest
     * requests fall back to it when the guest is not in the house yet.
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

    /**
     * One stay per line (SPEC-023), cancelled ones included (history).
     */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    /**
     * The reservation's primary stay: its earliest non-cancelled one. Kept for
     * the callers that ask about the guest rather than a room — pitching,
     * booking and outcome attribution, contact timestamps — for which any
     * live stay of the reservation is the right answer.
     */
    public function stay(): HasOne
    {
        // Ordered rather than ofMany(): Postgres has no min() for uuid, and a
        // HasOne keeps the first row of its ordered query, eager-loaded or not.
        return $this->hasOne(Stay::class)
            ->where('status', '!=', StayStatus::CANCELLED->value)
            ->orderBy('created_at')
            ->orderBy('id');
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
