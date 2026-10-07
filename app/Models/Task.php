<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\CancellationResolution;
use App\Enums\CleaningReason;
use App\Enums\CreatedBy;
use App\Enums\GuestNoticeChannel;
use App\Enums\GuestNoticeReason;
use App\Enums\GuestNoticeStatus;
use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Enums\InspectionResult;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Jobs\SendGuestRequestNoticeJob;
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
        // A cancellation request's answer. Set only by
        // BookingCancellationService and BookingService (forceFill).
        'resolution' => CancellationResolution::class,
        'resolved_at' => 'datetime',
        // Whether the guest was told how their request ended (SPEC-007).
        // Written only by SendGuestRequestNoticeJob and the migration;
        // never fillable.
        'guest_notice_status' => GuestNoticeStatus::class,
        'guest_notice_channel' => GuestNoticeChannel::class,
        'guest_notice_reason' => GuestNoticeReason::class,
        'guest_notice_at' => 'datetime',
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

        // A guest request just closed: tell the guest (SPEC-007, R7). A model
        // hook rather than an explicit call like CreationNotificationService,
        // because at least six paths close tasks (task API, inspections,
        // housekeeping, maintenance, cancellation approve and decline) and a
        // path that forgot the call would leave a guest never told. Seeders
        // and imports never close guest-signalled tasks.
        static::updated(function (self $task): void {
            if ($task->wasChanged('status')) {
                $task->queueGuestNotice();
            }
        });
    }

    /**
     * Queue the guest's notice for this request, once it is closed: completed
     * or cancelled service, maintenance and room-change requests, and decided
     * cancellation requests. Escalations, follow-ups and staff tasks never
     * notify. Runs after commit, so a rolled-back close tells nobody.
     */
    public function queueGuestNotice(): void
    {
        $closed = in_array($this->status, [TaskStatus::COMPLETED, TaskStatus::CANCELLED], true);
        $notifies = $this->guest_signal?->notifiesOnCompletion() || $this->guest_signal === GuestSignal::CANCELLATION_REQUEST;

        if ($closed && $notifies && $this->guest_id !== null && $this->awaitsGuestNotice()) {
            SendGuestRequestNoticeJob::dispatch($this->id)->afterCommit();
        }
    }

    /**
     * Not told yet. A request staff cancelled was recorded as skipped with
     * nothing sent, so if it is reopened and completed the guest is still
     * owed the completion notice; a stale pending claim was abandoned. Every
     * other recorded outcome is final.
     */
    public function awaitsGuestNotice(): bool
    {
        return $this->guest_notice_status === null
            || ($this->guest_notice_status === GuestNoticeStatus::SKIPPED
                && $this->guest_notice_reason === GuestNoticeReason::CANCELLED
                && $this->status === TaskStatus::COMPLETED)
            // A claim left by a worker that died mid-send.
            || ($this->guest_notice_status === GuestNoticeStatus::PENDING
                && $this->guest_notice_at?->lt(now()->subMinutes(SendGuestRequestNoticeJob::STALE_CLAIM_MINUTES)));
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
            'source_task_id', 'booking_id', 'resolution', 'resolution_note',
            'guest_notice_status', 'guest_notice_channel', 'guest_notice_reason',
        ];
    }

    /**
     * Pending or in progress.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS]);
    }

    /**
     * Tasks the Concierge files for a guest: service, maintenance and
     * room-change requests, cancellation requests and escalations.
     */
    public function scopeGuestRequests(Builder $query): Builder
    {
        return $query->whereIn('guest_signal', [
            GuestSignal::SERVICE_REQUEST->value,
            GuestSignal::MAINTENANCE_REQUEST->value,
            GuestSignal::ROOM_CHANGE_REQUEST->value,
            GuestSignal::CANCELLATION_REQUEST->value,
            GuestSignal::ESCALATION->value,
        ]);
    }

    /**
     * One guest's tasks at one hotel. The Concierge runs without tenant
     * context, so both are named.
     */
    public function scopeOwnedByGuest(Builder $query, Hotel $hotel, Guest $guest): Builder
    {
        return $query->withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('guest_id', $guest->id);
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

    /**
     * The booking a guest asked to cancel (SPEC-043).
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isCancellationRequest(): bool
    {
        return $this->guest_signal === GuestSignal::CANCELLATION_REQUEST;
    }
}
