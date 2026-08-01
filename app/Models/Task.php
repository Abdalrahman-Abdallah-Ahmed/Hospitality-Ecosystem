<?php

namespace App\Models;

use App\Enums\CreatedBy;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use Filterable, HasUuids, SoftDeletes;

    protected $fillable = [
        'hotel_id',
        'room_id',
        'reservation_id',
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
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
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
