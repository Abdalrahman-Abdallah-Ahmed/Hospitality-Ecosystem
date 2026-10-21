<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ReservationRoomStatus;
use App\Enums\StayStatus;
use App\Exceptions\CheckInOutException;
use App\Jobs\ExtractGuestMemoriesJob;
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

    protected static function booted(): void
    {
        // A check-out is when a guest's memories are extracted (SPEC-084).
        // On the model, so every check-out path counts — the staff endpoint,
        // the Admin AI, a whole reservation at once. After commit, so a
        // check-out that rolls back extracts nothing. Forward only: stays
        // that departed before this shipped are never extracted.
        static::updated(function (Stay $stay): void {
            if ($stay->wasChanged('status') && $stay->status === StayStatus::DEPARTED
                && $stay->guest_id !== null && $stay->reservation_id !== null) {
                ExtractGuestMemoriesJob::dispatch($stay->hotel_id, $stay->guest_id, $stay->reservation_id)->afterCommit();
            }
        });
    }

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
     * Still part of the booking: neither the stay nor its line was cancelled.
     * The one definition check-in, check-out and no-shows share.
     */
    public function isLive(): bool
    {
        return $this->status !== StayStatus::CANCELLED
            && $this->reservationRoom?->status !== ReservationRoomStatus::CANCELLED;
    }

    /**
     * The stay's reservation, whatever the tenant scope, for the front-desk
     * actions on one room (check-in/out, no-shows).
     *
     * @throws CheckInOutException when the stay has none
     */
    public function reservationOrFail(): Reservation
    {
        $reservation = $this->reservation_id
            ? Reservation::withoutGlobalScope('hotel')->find($this->reservation_id)
            : null;

        if (! $reservation) {
            throw CheckInOutException::for(["stays.{$this->id}.reservation" => ['This stay has no reservation to check in or out.']]);
        }

        return $reservation;
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
