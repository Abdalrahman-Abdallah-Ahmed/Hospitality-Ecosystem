<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'max_occupancy',
        'adult_capacity',
        'child_capacity',
        'bed_configuration',
        'amenities',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'bed_configuration' => 'json',
        'amenities' => 'json',
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'room_type_id');
    }
}
