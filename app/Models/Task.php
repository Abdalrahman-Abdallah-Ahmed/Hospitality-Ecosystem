<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
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
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'room_id', 'reservation_id', 'stay_id', 'guest_id', 'assigned_to_team_id',
            'assigned_to_user_id', 'task_category_id', 'title', 'description',
            'created_by', 'guest_signal', 'status', 'priority', 'due_date',
        ];
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
}
