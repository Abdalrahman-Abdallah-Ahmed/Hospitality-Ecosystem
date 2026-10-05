<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\CleaningReason;
use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Enums\InspectionResult;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'room_id',
        'reservation_id',
        'stay_id',
        'guest_id',
        'assigned_to_team_id',
        'assigned_to_user_id',
        'task_category_id',
        'created_by_user_id',
        'title',
        'description',
        'created_by',
        'status',
        'priority',
        'due_date',
    ];

    protected $casts = [
        'created_by' => CreatedBy::class,
        'status' => TaskStatus::class,
        'priority' => Priority::class,
        'due_date' => 'datetime',
        // Set only by the concierge tools; deliberately not fillable, so the
        // API cannot relabel a complaint and reopen pitching to the guest.
        'guest_signal' => GuestSignal::class,
        // Set only by HousekeepingService and MaintenanceService
        // (forceFill); never client-writable.
        'housekeeping_kind' => HousekeepingKind::class,
        'cleaning_reason' => CleaningReason::class,
        'inspection_result' => InspectionResult::class,
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // When the task was finished, so an issue can be reported on a
        // housekeeping task on the day it was completed (FR-022).
        static::saving(function (self $task): void {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === TaskStatus::COMPLETED ? now() : null;
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'room_id', 'reservation_id', 'stay_id', 'guest_id', 'assigned_to_team_id',
            'assigned_to_user_id', 'task_category_id', 'title', 'description',
            'created_by', 'guest_signal', 'status', 'priority', 'due_date',
            'housekeeping_kind', 'cleaning_reason', 'inspection_result', 'inspection_note',
            'source_task_id',
        ];
    }

    /**
     * Pending or in progress.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [TaskStatus::PENDING, TaskStatus::IN_PROGRESS], true);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function assignedToTeam()
    {
        return $this->belongsTo(Team::class, 'assigned_to_team_id');
    }

    public function assignedToUser()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function taskCategory()
    {
        return $this->belongsTo(TaskCategory::class);
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The housekeeping task a maintenance task was reported from (SPEC-035).
     */
    public function sourceTask()
    {
        return $this->belongsTo(Task::class, 'source_task_id');
    }

    public function maintenanceTasks()
    {
        return $this->hasMany(Task::class, 'source_task_id');
    }
}
