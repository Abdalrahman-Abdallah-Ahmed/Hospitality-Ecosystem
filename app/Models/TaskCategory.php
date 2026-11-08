<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsImpersonatedWrites;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskCategory extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsImpersonatedWrites;

    public $fillable = [
        'hotel_id',
        'team_id',
        'name',
        'description',
    ];

    /**
     * @return list<string>
     */
    protected function hotelSettingsClearedOnDelete(): array
    {
        return ['cleaning_task_category_id', 'inspection_task_category_id', 'maintenance_task_category_id'];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
