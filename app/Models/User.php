<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\Filterable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone_number', 'team_id', 'hotel_id', 'hotel_group_id', 'group_role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Filterable, HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Every hotel this user has been explicitly granted access to via the
     * hotel_user pivot (independent of the legacy single hotel_id, and of
     * group-wide access via hotel_group_id/group_role).
     */
    public function hotels()
    {
        return $this->belongsToMany(Hotel::class, 'hotel_user')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function hotelGroup()
    {
        return $this->belongsTo(HotelGroup::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Every hotel id this user may see, or null when unrestricted (super
     * admin). Used by ResolveTenant to populate TenantContext for
     * BelongsToHotel's global scope — never returns null for a merely
     * hotel-less regular user, since that would mean "see everything"
     * instead of the correct "see nothing".
     */
    public function accessibleHotelIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        if ($this->group_role && $this->hotel_group_id) {
            return Hotel::where('hotel_group_id', $this->hotel_group_id)->pluck('id')->all();
        }

        $ids = $this->hotels()->pluck('hotels.id')->all();

        if ($this->hotel_id && ! in_array($this->hotel_id, $ids, true)) {
            $ids[] = $this->hotel_id;
        }

        return $ids;
    }

    public function whatsappDevice()
    {
        return $this->hasOne(WhatsAppDevice::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isEmployee(): bool
    {
        return $this->role === UserRole::EMPLOYEE;
    }
}
