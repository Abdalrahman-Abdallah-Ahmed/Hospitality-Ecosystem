<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use Filterable, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'owner_id',
        'hotel_group_id',
        'name',
        'slug',
        'timezone',
        'currency',
        'country_code',
        'city',
        'address',
        'whatsapp_number',
        'email',
        'phone',
        'branding',
        'ai_preferences',
        'is_active',
    ];

    protected $casts = [
        'branding' => 'array',
        'ai_preferences' => 'array',
        'is_active' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hotelGroup(): BelongsTo
    {
        return $this->belongsTo(HotelGroup::class);
    }

    /**
     * Users explicitly granted access to this hotel via the hotel_user
     * pivot — distinct from users() above, which is the legacy single
     * hotel_id relation.
     */
    public function pivotUsers()
    {
        return $this->belongsToMany(User::class, 'hotel_user')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function taskCategories(): HasMany
    {
        return $this->hasMany(TaskCategory::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function activityCategories(): HasMany
    {
        return $this->hasMany(ActivityCategory::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(HotelPolicy::class);
    }

    public function knowledgeBaseArticles(): HasMany
    {
        return $this->hasMany(KnowledgeBaseArticle::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}
