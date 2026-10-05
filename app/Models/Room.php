<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use BelongsToHotel, Filterable, HasFactory, HasUuids;
    use RecordsEvents {
        loggedChangeSet as baseLoggedChangeSet;
    }

    /**
     * Extra values for the audit row of the next save, not persisted: what
     * caused a housekeeping or service change (`cause`), the task behind it
     * (`task_id`) and a staff note (`reason`). Set by HousekeepingService and
     * MaintenanceService right before the save and cleared after it.
     *
     * @var array<string, mixed>
     */
    public array $auditExtras = [];

    /**
     * The room's hotel, set by list endpoints so RoomResource can tell
     * readiness without reading the hotel once per room. Not persisted.
     */
    public ?Hotel $readinessHotel = null;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The API never writes `status` or `housekeeping_status` (RoomController
     * rejects them); stays, HousekeepingService and MaintenanceService do.
     * The out-of-order columns are not fillable at all.
     */
    protected $fillable = [
        'hotel_id',
        'room_number',
        'room_type_id',
        'floor',
        'building',
        'status',
        'housekeeping_status',
    ];

    protected $casts = [
        'status' => RoomStatusesEnum::class,
        'housekeeping_status' => HousekeepingStatusesEnum::class,
        'housekeeping_status_changed_at' => 'datetime',
        'out_of_order_since' => 'datetime',
        'out_of_order_until' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $room): void {
            $room->housekeeping_status_changed_at ??= now();
        });
    }

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'room_number', 'room_type_id', 'floor', 'building', 'status', 'housekeeping_status',
            'out_of_order_reason', 'out_of_order_until', 'out_of_order_task_id',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loggedChangeSet(bool $withFrom): array
    {
        $set = $this->baseLoggedChangeSet($withFrom);

        foreach ($this->auditExtras as $key => $value) {
            if ($value !== null) {
                $set[$key] = ['to' => EventLogger::normalize($value)];
            }
        }

        return $set;
    }

    /**
     * Service and housekeeping changes are named events, so the room's
     * history reads like what happened (research R13).
     */
    public function eventVerbFor(string $verb): string
    {
        if ($verb !== 'updated') {
            return $verb;
        }

        $changes = $this->getChanges();

        if (array_key_exists('status', $changes)) {
            if ($this->status === RoomStatusesEnum::OUT_OF_ORDER) {
                return 'taken_out_of_order';
            }

            if ($this->getOriginal('status') === RoomStatusesEnum::OUT_OF_ORDER) {
                return 'returned_to_service';
            }
        }

        if (array_key_exists('housekeeping_status', $changes)) {
            return 'housekeeping_changed';
        }

        $outOfOrderFields = ['out_of_order_reason', 'out_of_order_until', 'out_of_order_task_id'];

        if (array_intersect(array_keys($changes), $outOfOrderFields) !== []) {
            return 'out_of_order_updated';
        }

        return $verb;
    }

    /**
     * Whether the room is out of service and must not take a guest.
     */
    public function isOutOfOrder(): bool
    {
        return $this->status === RoomStatusesEnum::OUT_OF_ORDER;
    }

    /**
     * Hands the room its hotel, so RoomResource can report readiness.
     */
    public function withReadiness(): static
    {
        $this->readinessHotel ??= Hotel::find($this->hotel_id);

        return $this;
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function outOfOrderBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'out_of_order_by_user_id');
    }

    public function outOfOrderTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'out_of_order_task_id');
    }
}
