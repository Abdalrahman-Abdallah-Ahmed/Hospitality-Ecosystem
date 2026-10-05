<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * That a hotel's start-of-day housekeeping ran for one of its local dates.
 * The unique (hotel_id, day) row is what makes the run happen once (FR-013).
 */
class HousekeepingDayRun extends Model
{
    use BelongsToHotel, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'hotel_id',
        'day',
        'rooms_dirtied',
        'tasks_created',
    ];

    protected $casts = [
        'day' => 'date',
        'rooms_dirtied' => 'integer',
        'tasks_created' => 'integer',
    ];
}
