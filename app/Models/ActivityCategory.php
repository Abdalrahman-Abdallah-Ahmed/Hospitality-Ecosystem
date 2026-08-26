<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityCategory extends Model
{
    use BelongsToHotel, Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'name',
        'slug',
        'description',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'category_id');
    }
}
