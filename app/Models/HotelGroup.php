<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HotelGroup extends Model
{
    use Filterable, HasUuids, SoftDeletes;

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
