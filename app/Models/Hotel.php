<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use App\Services\Metering\MeteringService;
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

    protected static function booted(): void
    {
        // Every hotel belongs to an account. A hotel created without a group
        // gets a single-property group of its own, so that group-keyed code
        // never has to handle a hotel with no account. Doing it here rather
        // than in the two controllers that create hotels covers the ordinary
        // entry points at once.
        //
        // It does NOT cover everything: anything that suppresses model events
        // (DatabaseSeeder uses WithoutModelEvents) or writes through the query
        // builder bypasses this and must set the group itself. The NOT NULL
        // constraint on the column is the actual guarantee — this hook is
        // only the convenience.
        static::creating(function (self $hotel): void {
            if ($hotel->hotel_group_id === null) {
                $hotel->hotel_group_id = HotelGroup::singlePropertyFor($hotel)->id;
            }
        });

        // Properties are a seat: the count is read back from this table, never
        // incremented. Incrementing would leave a property count that only
        // ever rises, surviving every hotel anyone deletes — which is exactly
        // what the recount on delete is here to prevent.
        static::created(fn (self $hotel) => $hotel->recountSeats());
        static::deleted(fn (self $hotel) => $hotel->recountSeats());

        // A hotel can move between accounts — the single-property group above
        // is explicitly a placeholder that hotels get reassigned out of once
        // a real group is formed. Both sides are recounted: the group gaining
        // the property and the one losing it.
        static::updated(function (self $hotel): void {
            if (! $hotel->wasChanged('hotel_group_id')) {
                return;
            }

            $hotel->recountSeats();

            self::recountAccount($hotel->getOriginal('hotel_group_id'));
        });
    }

    private function recountSeats(): void
    {
        self::recountAccount($this->getAttribute('hotel_group_id'));
    }

    /**
     * Takes a raw id rather than a model so it can be called with a hotel's
     * *previous* group, which is how the account a hotel just left gets
     * corrected.
     */
    private static function recountAccount(?string $hotelGroupId): void
    {
        if (! $hotelGroupId) {
            return;
        }

        $metering = app(MeteringService::class);

        $metering->safely(function (MeteringService $m) use ($hotelGroupId): void {
            if ($account = HotelGroup::withTrashed()->find($hotelGroupId)) {
                $m->recountSeats($account);
            }
        });
    }

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

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
