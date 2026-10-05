<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * That a person has been told about a task's current assignment. Inserted
 * before the notice goes out and deleted when the assignment moves away from
 * them, so a resave, retry or repeat never notifies anyone twice (FR-027).
 * Reached only through a hotel-scoped task.
 */
class TaskNotificationReceipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'user_id',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
