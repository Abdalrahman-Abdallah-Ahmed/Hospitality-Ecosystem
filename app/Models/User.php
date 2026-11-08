<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\GroupRole;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Concerns\Filterable;
use App\Services\Metering\MeteringService;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone_number', 'team_id', 'hotel_id', 'hotel_group_id', 'group_role', 'staff_role_id'])]
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
            'group_role' => GroupRole::class,
        ];
    }

    protected static function booted(): void
    {
        // Users are a seat, like properties: recounted from this table, never
        // incremented, so that a departed staff member actually leaves the
        // count.
        static::created(fn (self $user) => $user->recountSeats());
        static::deleted(function (self $user): void {
            $user->recountSeats();
            self::forgetPasswordLinks($user->email);
        });

        // Password links are keyed by email, so one left behind would work
        // again for whoever holds that address next.
        static::updated(function (self $user): void {
            if ($user->wasChanged('email')) {
                self::forgetPasswordLinks($user->getOriginal('email'));
            }
        });

        // A user is very often linked to their hotel AFTER being created —
        // registration makes the user, then the hotel, then attaches one to
        // the other — so `created` alone fires while the user still belongs
        // to nothing and leaves the account reading zero users forever.
        //
        // Only attribute changes that move a user between accounts matter;
        // recounting on every name or password change would run four count
        // queries for nothing.
        static::updated(function (self $user): void {
            if (! $user->wasChanged(['hotel_id', 'hotel_group_id'])) {
                return;
            }

            // Both sides: a user who moved has left one account and joined
            // another, and the account they left is now one seat lighter.
            // Recounting only the new one would leave the old one overstated.
            $user->recountSeats();

            self::recountAccount(
                $user->getOriginal('hotel_group_id'),
                $user->getOriginal('hotel_id'),
            );
        });
    }

    private static function forgetPasswordLinks(string $email): void
    {
        foreach (config('auth.passwords') as $broker) {
            DB::table($broker['table'])->where('email', $email)->delete();
        }
    }

    /**
     * Resolved by query rather than through the `hotelGroup` / `hotel`
     * relation properties on purpose. Reading a relation here would cache it
     * on this instance — and at creation time a user usually has no hotel
     * yet, so the caller would be left holding an instance whose `hotel` is
     * permanently null even after it has been assigned one.
     */
    private function recountSeats(): void
    {
        self::recountAccount($this->getAttribute('hotel_group_id'), $this->getAttribute('hotel_id'));
    }

    /**
     * Recount the seats of whichever account those two attributes point at.
     *
     * Takes raw ids rather than a model so it can be called with a user's
     * *previous* values, which is how the account a user just left gets
     * corrected.
     */
    private static function recountAccount(?string $hotelGroupId, ?string $hotelId): void
    {
        $metering = app(MeteringService::class);

        $metering->safely(function (MeteringService $m) use ($hotelGroupId, $hotelId): void {
            if ($account = self::resolveAccount($hotelGroupId, $hotelId, withTrashed: true)) {
                $m->recountSeats($account);
            }
        });
    }

    /**
     * The account this user belongs to — the hotel group, which is the paying
     * customer.
     *
     * Resolved by query rather than through the `hotelGroup` / `hotel`
     * relation properties, for the reason given above recountSeats(): reading
     * a relation caches it on the instance, and a user usually has no hotel
     * at creation time.
     *
     * A user may be attached to the group directly, or only to a hotel that
     * belongs to one — both are normal, so both resolve.
     *
     * `withTrashed` exists for the seat recount, which must still find the
     * account of a user whose hotel was soft-deleted. Reporting uses the
     * default: a deleted account is not one anybody should be reading.
     */
    public function account(bool $withTrashed = false): ?HotelGroup
    {
        return self::resolveAccount(
            $this->getAttribute('hotel_group_id'),
            $this->getAttribute('hotel_id'),
            $withTrashed,
        );
    }

    private static function resolveAccount(?string $hotelGroupId, ?string $hotelId, bool $withTrashed = false): ?HotelGroup
    {
        $accountId = $hotelGroupId ?? Hotel::withTrashed()
            ->whereKey($hotelId)
            ->value('hotel_group_id');

        if (! $accountId) {
            return null;
        }

        return $withTrashed
            ? HotelGroup::withTrashed()->find($accountId)
            : HotelGroup::find($accountId);
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

    public function staffRole()
    {
        return $this->belongsTo(StaffRole::class);
    }

    /**
     * Whether this user may do this kind of thing at all. Policies still
     * check that the record belongs to the user's hotel.
     */
    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Every permission this user holds.
     *
     * Admins and super admins hold all of them. An employee holds what their
     * staff role grants, or the defaults when no role is assigned. An
     * assigned role that can no longer be read (deleted, or outside the
     * current tenant scope) grants nothing rather than falling back to the
     * defaults, which could be wider than the role was.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        if ($this->isSuperAdmin() || $this->isAdmin()) {
            return Permission::cases();
        }

        if (! $this->isEmployee()) {
            return [];
        }

        if ($this->staff_role_id === null) {
            return Permission::employeeDefaults();
        }

        return $this->staffRole?->grantedPermissions() ?? [];
    }

    /**
     * Every hotel id this user may see, or null when unrestricted (super
     * admin). Used by ResolveTenant to populate TenantContext for
     * BelongsToHotel's global scope — never returns null for a merely
     * hotel-less regular user, since that would mean "see everything"
     * instead of the correct "see nothing".
     *
     * reachableHotelsQuery() is this rule in SQL: a change here belongs there too.
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

        // A soft-deleted hotel is out of reach until it is restored. The two
        // branches above already leave those out.
        if ($this->hotel_id && ! in_array($this->hotel_id, $ids, true) && Hotel::whereKey($this->hotel_id)->exists()) {
            $ids[] = $this->hotel_id;
        }

        return $ids;
    }

    /**
     * accessibleHotelIds(), read from the request's tenant context when
     * ResolveTenant already worked it out for this user, so a policy check
     * doesn't query it again. Unlike TenantContext::hotelIds(), this is not
     * narrowed to the hotel the request works in.
     *
     * @return array<int, string>|null
     */
    public function reachableHotelIds(): ?array
    {
        return TenantContext::knowsReachableHotelIds($this->getKey())
            ? TenantContext::reachableHotelIds($this->getKey())
            : $this->accessibleHotelIds();
    }

    /**
     * Users attached to any of these accounts: directly, through a live home
     * hotel, or through a live membership.
     *
     * @param  array<int, string>  $accountIds
     */
    public function scopeAttachedToAccount(Builder $query, array $accountIds): Builder
    {
        return $query->where(fn (Builder $attached) => $attached
            ->whereIn('hotel_group_id', $accountIds)
            ->orWhereIn('hotel_id', Hotel::query()->select('id')->whereIn('hotel_group_id', $accountIds))
            ->orWhereHas('hotels', fn (Builder $hotels) => $hotels->whereIn('hotels.hotel_group_id', $accountIds)));
    }

    /**
     * Users who can reach any of these hotels, by the same three routes as
     * accessibleHotelIds(): home hotel, membership, or an account-wide role
     * in the hotel's account.
     *
     * @param  array<int, string>  $hotelIds
     */
    public function scopeAttachedToHotel(Builder $query, array $hotelIds): Builder
    {
        return $query->where(fn (Builder $attached) => $attached
            ->whereIn('hotel_id', $hotelIds)
            ->orWhereHas('hotels', fn (Builder $hotels) => $hotels->whereIn('hotels.id', $hotelIds))
            ->orWhere(fn (Builder $group) => $group
                ->whereNotNull('group_role')
                ->whereIn('hotel_group_id', Hotel::query()->select('hotel_group_id')->whereIn('id', $hotelIds))));
    }

    /**
     * Users whom suspension refuses at login: no active hotel left and at least
     * one suspended, or no hotels and a suspended account. HotelAccess answers
     * this for one user. This answers it for many without a query per user, and
     * DashboardTest compares the two against a real login for each kind of user.
     */
    public function scopeRefusedBySuspension(Builder $query): Builder
    {
        return $query
            ->whereNotExists(self::reachableHotelsQuery()->notSuspended()->select(DB::raw(1)))
            ->where(fn (Builder $refused) => $refused
                ->whereExists(self::reachableHotelsQuery()->select(DB::raw(1)))
                ->orWhere(fn (Builder $noHotels) => self::inSuspendedAccount($noHotels)));
    }

    /**
     * accessibleHotelIds() as a subquery on `users`: an owner reaches their
     * account's live hotels and nothing else. Anyone else reaches their live home
     * hotel and memberships, and a direct account link with no group role adds
     * nothing.
     */
    private static function reachableHotelsQuery(): Builder
    {
        return Hotel::query()->where(fn (Builder $hotels) => $hotels
            ->where(fn (Builder $owner) => $owner
                ->whereNotNull('users.group_role')
                ->whereNotNull('users.hotel_group_id')
                ->whereColumn('hotels.hotel_group_id', 'users.hotel_group_id'))
            ->orWhere(fn (Builder $other) => $other
                ->where(fn (Builder $notOwner) => $notOwner
                    ->whereNull('users.group_role')
                    ->orWhereNull('users.hotel_group_id'))
                ->where(fn (Builder $own) => $own
                    ->whereColumn('hotels.id', 'users.hotel_id')
                    ->orWhereExists(DB::table('hotel_user')
                        ->select(DB::raw(1))
                        ->whereColumn('hotel_user.hotel_id', 'hotels.id')
                        ->whereColumn('hotel_user.user_id', 'users.id')))));
    }

    /**
     * account(withTrashed: true) in SQL: the direct link when there is one,
     * otherwise the account of the home hotel, soft-deleted or not.
     */
    private static function inSuspendedAccount(Builder $query): void
    {
        $suspended = HotelGroup::withTrashed()->whereNotNull('suspended_at')->select('id');

        $query
            ->whereIn('users.hotel_group_id', $suspended)
            ->orWhere(fn (Builder $viaHome) => $viaHome
                ->whereNull('users.hotel_group_id')
                ->whereIn('users.hotel_id', Hotel::withTrashed()->whereIn('hotel_group_id', $suspended)->select('id')));
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

    /**
     * Whether this user owns an account, under the same condition on which
     * accessibleHotelIds() gives them all of it.
     */
    public function isOwner(): bool
    {
        return $this->group_role !== null && $this->hotel_group_id !== null;
    }
}
