<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\StayStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use App\Support\Audit\EventLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stay extends Model
{
    use BelongsToHotel, Filterable, HasUuids, SoftDeletes;
    use RecordsEvents {
        loggedChangeSet as baseLoggedChangeSet;
    }

    /**
     * Extra values for the audit row of the next save, not persisted: the
     * moment a late-entered check-in or check-out was actually recorded
     * (`entered_at`), so one row shows both times (research R17). Set by
     * StayLifecycleService right before the save and cleared after it.
     *
     * @var array<string, mixed>
     */
    public array $auditExtras = [];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'guest_id',
        'reservation_id',
        'reservation_room_id',
        'room_id',
        'planned_arrival_date',
        'planned_departure_date',
        'checked_in_at',
        'checked_out_at',
        'status',
        'adults',
        'children',
        'nights',
        'room_revenue',
        'currency',
        'market_segment',
        'source_channel',
    ];

    protected $casts = [
        'planned_arrival_date' => 'date',
        'planned_departure_date' => 'date',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'status' => StayStatus::class,
        'adults' => 'integer',
        'children' => 'integer',
        'nights' => 'integer',
        'room_revenue' => 'decimal:2',
        // Written only by GuestContactService; deliberately not fillable.
        'first_contacted_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'guest_id', 'reservation_id', 'reservation_room_id', 'room_id', 'planned_arrival_date',
            'planned_departure_date', 'checked_in_at', 'checked_out_at',
            'status', 'adults', 'children', 'nights', 'room_revenue',
            'currency', 'market_segment', 'source_channel',
        ];
    }

    /**
     * `?search=` on the stays list: the guest's name or phone, or the
     * reservation's code — a stay's own columns hold nothing worth typing.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(fn (Builder $query) => $query
            ->whereHas('guest', fn (Builder $guest) => $guest->where(fn (Builder $q) => $q
                ->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('phone_number', 'like', $like)))
            ->orWhereHas('reservation', fn (Builder $reservation) => $reservation->where('reservation_id', 'ilike', $like)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loggedChangeSet(bool $withFrom): array
    {
        $set = $this->baseLoggedChangeSet($withFrom);

        foreach ($this->auditExtras as $key => $value) {
            $set[$key] = ['to' => EventLogger::normalize($value)];
        }

        return $set;
    }

    /**
     * A status change is a lifecycle event, not a generic edit — surface it
     * as stay.checked_in / checked_out / no_show / cancelled / expected so the
     * history reads like what actually happened.
     */
    public function eventVerbFor(string $verb): string
    {
        if ($verb !== 'updated' || ! array_key_exists('status', $this->getChanges())) {
            return $verb;
        }

        return match ($this->status) {
            StayStatus::IN_HOUSE => 'checked_in',
            StayStatus::DEPARTED => 'checked_out',
            StayStatus::NO_SHOW => 'no_show',
            StayStatus::CANCELLED => 'cancelled',
            StayStatus::EXPECTED => 'expected',
        };
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The reservation line this stay is the guest presence of: one stay per
     * line (SPEC-023). Null only for legacy stays with no reservation.
     */
    public function reservationRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Rooms occupied on a given date, for this hotel. A room counts as
     * occupied on `$date` when a stay's planned window covers it — checked
     * in and still here (IN_HOUSE) or already known to have stayed
     * (DEPARTED, since a past date can still ask "were they here then?").
     * Departure day itself does not count: a guest leaving on the 5th did
     * not sleep there on the night of the 5th, so the comparison is a
     * strict `<` on planned_departure_date, not `<=`.
     *
     * Every stay is one room (one stay per reservation line), so this is a
     * plain count.
     */
    public static function occupiedRoomsOn(Hotel $hotel, CarbonInterface $date): int
    {
        return static::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('status', [StayStatus::IN_HOUSE, StayStatus::DEPARTED])
            ->whereDate('planned_arrival_date', '<=', $date)
            ->whereDate('planned_departure_date', '>', $date)
            ->count();
    }
}
