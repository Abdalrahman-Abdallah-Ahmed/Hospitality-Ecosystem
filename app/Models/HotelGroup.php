<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HotelGroup extends Model
{
    use Filterable, HasFactory, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'country_code',
        'default_currency',
        'default_timezone',
        'settings',
        'is_active',
        'contract_value_monthly',
        'contract_currency',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'contract_value_monthly' => 'decimal:2',
    ];

    /**
     * What ?search= reads. A list, because Filterable would otherwise search
     * every text column, contract_currency included.
     *
     * @var list<string>
     */
    public array $searchable = ['name', 'slug'];

    /**
     * Adds users_count: every user who is not a super admin and is attached
     * through the account, a live home hotel or a live membership. Counted on
     * the users table with no join, so a user attached three ways counts once.
     *
     * Differs from the metering seat count on purpose, which ignores
     * memberships.
     */
    public function scopeWithUserCount(Builder $query): Builder
    {
        // selectSub alone would leave the account's own columns unselected.
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->selectSub(
            User::query()
                ->selectRaw('count(*)')
                ->where('users.role', '!=', UserRole::SUPER_ADMIN->value)
                ->where(fn (Builder $attached) => $attached
                    ->whereColumn('users.hotel_group_id', 'hotel_groups.id')
                    ->orWhereIn('users.hotel_id', Hotel::query()
                        ->select('hotels.id')
                        ->whereColumn('hotels.hotel_group_id', 'hotel_groups.id'))
                    ->orWhereExists(Hotel::query()
                        ->select(DB::raw(1))
                        ->join('hotel_user', 'hotel_user.hotel_id', '=', 'hotels.id')
                        ->whereColumn('hotel_user.user_id', 'users.id')
                        ->whereColumn('hotels.hotel_group_id', 'hotel_groups.id'))),
            'users_count',
        );
    }

    /**
     * Build the single-property group that a hotel arriving without one gets
     * automatically. Every hotel belongs to a group so that account-level
     * code — metering, cost attribution, and eventually billing — has exactly
     * one code path instead of a null check at every call site.
     *
     * The group mirrors the hotel it wraps. When a real group is formed later
     * the hotels are reassigned to it and these wrappers are left behind
     * empty; that is cheaper than making the column optional again.
     */
    public static function singlePropertyFor(Hotel $hotel): self
    {
        return static::create([
            'name' => $hotel->name,
            'slug' => static::availableSlug($hotel->slug ?: Str::slug((string) $hotel->name)),
            'country_code' => $hotel->country_code,
            'default_currency' => $hotel->currency ?: 'USD',
            'default_timezone' => $hotel->timezone ?: 'UTC',
        ]);
    }

    /**
     * Group slugs are unique and outlive their hotels — a soft-deleted group
     * still holds its slug — so a hotel slug is a starting point, not a
     * guarantee. Suffix until the name is free.
     */
    protected static function availableSlug(string $base): string
    {
        $base = $base ?: 'group';
        $slug = $base;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
