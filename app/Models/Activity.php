<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\ActivityAudience;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use BelongsToHotel, Filterable, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'category_id',
        'name',
        'description',
        'price',
        'currency',
        'is_active',
        'available_from',
        'available_until',
        'operating_hours',
        'unavailable_periods',
        'audience',
        'duration_days',
        'daily_capacity',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'available_from' => 'date',
        'available_until' => 'date',
        'operating_hours' => 'array',
        'unavailable_periods' => 'array',
        'audience' => ActivityAudience::class,
        'duration_days' => 'integer',
        'daily_capacity' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'category_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
