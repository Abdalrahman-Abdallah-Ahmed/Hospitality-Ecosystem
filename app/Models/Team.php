<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsImpersonatedWrites;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsImpersonatedWrites;

    public $fillable = [
        'hotel_id',
        'name',
        'description',
        'is_active',
    ];

    /**
     * @return list<string>
     */
    protected function hotelSettingsClearedOnDelete(): array
    {
        return ['housekeeping_team_id', 'maintenance_team_id'];
    }

    public function members()
    {
        return $this->hasMany(User::class, 'team_id');
    }

    public function taskCategories()
    {
        return $this->hasMany(TaskCategory::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'assigned_to_team_id');
    }
}
