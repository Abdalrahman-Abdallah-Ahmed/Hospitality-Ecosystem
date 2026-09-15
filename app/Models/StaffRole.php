<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\Permission;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named set of permissions an admin defines for their hotel ("Front Desk",
 * "Housekeeping") and assigns to employees. Admins never hold one: they
 * already have every permission.
 */
class StaffRole extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'name',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return ['name', 'description', 'permissions'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The stored permissions as enum cases. A value no longer in the enum is
     * skipped rather than thrown on, so retiring a permission never breaks a
     * role that still lists it.
     *
     * @return list<Permission>
     */
    public function grantedPermissions(): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => Permission::tryFrom($value),
            $this->permissions ?? [],
        )));
    }
}
