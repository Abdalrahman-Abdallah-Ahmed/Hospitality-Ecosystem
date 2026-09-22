<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use BelongsToHotel, Filterable, HasFactory, HasUuids, RecordsEvents, SoftDeletes;

    public const DEFAULT_NAME = 'Standard';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'hotel_id',
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

    public function eventLoggedAttributes(): array
    {
        return [
            'name', 'description', 'max_occupancy', 'adult_capacity', 'child_capacity',
            'bed_configuration', 'amenities', 'base_price', 'is_active',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'room_type_id');
    }

    /**
     * The room type to file a room under when it is created without an explicit
     * type id (reservation import, AI admin tool). A named type is matched
     * case-insensitively and created when missing; with no name, the hotel's
     * oldest active type is used, or a "Standard" type is created.
     *
     * Soft-deleted types are never matched: a deleted name is free for reuse,
     * and restoring would silently bring back its old capacity and price.
     */
    public static function resolveFor(string $hotelId, ?string $name = null): self
    {
        $name = trim((string) $name);

        $hotelTypes = fn () => static::withoutGlobalScope('hotel')->where('hotel_id', $hotelId);

        if ($name === '') {
            $existing = $hotelTypes()->where('is_active', true)->oldest()->first();

            if ($existing) {
                return $existing;
            }

            $name = self::DEFAULT_NAME;
        }

        $match = $hotelTypes()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        return $match ?? static::create([
            'hotel_id' => $hotelId,
            'name' => $name,
            'description' => 'Created automatically; review capacity and price.',
            'max_occupancy' => 2,
            'adult_capacity' => 2,
            'child_capacity' => 0,
            'base_price' => 0,
        ]);
    }
}
