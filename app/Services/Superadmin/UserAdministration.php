<?php

namespace App\Services\Superadmin;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\Hotel;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\Superadmin\Concerns\AppliesAuditedChanges;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a super admin does to a user, and the rules the tenant UserController
 * shares with it. A super admin never knows a user's password: a new user gets
 * a random one and sets their own from an emailed link.
 */
class UserAdministration
{
    use AppliesAuditedChanges;

    public const SUPER_ADMIN_REFUSAL = 'The super admin role cannot be assigned through the API.';

    /** A new user's first link, which lasts longer than a reset link. */
    private const FIRST_LINK_BROKER = 'invitations';

    private const RESET_BROKER = 'users';

    /**
     * Nobody can grant this role through the API, whoever is asking. Super
     * admins come only from the seeder.
     */
    public static function refusesSuperAdminGrant(?string $role): bool
    {
        return $role === UserRole::SUPER_ADMIN->value;
    }

    /**
     * A staff role can only be given to an employee — admins already hold
     * every permission — and must belong to the user's hotel.
     *
     * @return array{message: string, status: int}|null
     */
    public static function staffRoleError(?string $staffRoleId, ?Hotel $hotel, string $role): ?array
    {
        if ($staffRoleId === null) {
            return null;
        }

        if ($role !== UserRole::EMPLOYEE->value) {
            return ['message' => 'Only employees can be given a staff role.', 'status' => 422];
        }

        if (! $hotel || invalidRelation($hotel, ['staffRoles' => $staffRoleId])) {
            return ['message' => 'The selected staff role does not belong to you.', 'status' => 403];
        }

        return null;
    }

    /**
     * The email goes out after the user is committed, so a mail failure can
     * never undo the creation. The second value says whether it was sent.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: bool}
     */
    public function create(array $attributes): array
    {
        $this->refuseSuperAdminGrant($attributes['role'] ?? null);

        $user = DB::transaction(function () use ($attributes) {
            $hotel = Hotel::findOrFail($attributes['hotel_id']);

            $user = User::create([
                ...$attributes,
                'hotel_group_id' => $this->accountForNewUser($hotel, $attributes),
                'password' => Str::password(32),
            ]);

            if ($user->isAdmin()) {
                $this->claimOwnership($hotel->getKey(), $user);
            }

            EventLogger::recordPlatform('platform.user.created', $user, $this->createdChanges($user));

            return $user;
        });

        return [$user, $this->sendFirstLink($user)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes) {
            if ($changes = $this->applyChanges($user, $attributes)) {
                EventLogger::recordPlatform('platform.user.updated', $user, $changes);
            }

            return $user;
        });
    }

    /**
     * Change what a user can reach: role, account-wide role, memberships and
     * staff role. All or nothing, so a refused step leaves the user as they
     * were.
     *
     * @param  array<string, mixed>  $input
     */
    public function changeAccess(User $user, array $input): User
    {
        $this->refuseSuperAdminGrant($input['role'] ?? null);

        return TenantContext::withoutScope(fn () => DB::transaction(function () use ($user, $input) {
            $wasAdmin = $user->isAdmin();
            $role = $input['role'] ?? $user->role->value;
            $isAdmin = $role === UserRole::ADMIN->value;
            $membershipsBefore = $this->liveMembershipIds($user);

            $attributes = ['role' => $role, ...$this->groupRoleAttributes($user, $input, $isAdmin)];
            $attributes['staff_role_id'] = $this->staffRoleFor($user, $input, $role);

            if (array_key_exists('team_id', $input)) {
                $attributes['team_id'] = $this->teamFor($user, $input['team_id']);
            }

            $changes = $this->applyChanges($user, $attributes);

            if (array_key_exists('hotel_ids', $input)) {
                $this->replaceMemberships($user, $input['hotel_ids'], $role);
            }

            if (isset($changes['role'])) {
                DB::table('hotel_user')->where('user_id', $user->getKey())->update(['role' => $role]);
                $this->moveOwnershipWithRole($user, $wasAdmin);
            }

            $membershipsAfter = $this->liveMembershipIds($user);

            if ($membershipsBefore !== $membershipsAfter) {
                $changes['hotel_ids'] = ['from' => $membershipsBefore, 'to' => $membershipsAfter];
            }

            if ($changes !== []) {
                EventLogger::recordPlatform('platform.user.access_changed', $user, $changes);
            }

            return $user;
        }));
    }

    /**
     * Send a reset link, which replaces any link still pending. False means the
     * mail could not be sent, which has been reported. It is sent inside the
     * transaction so that a failure undoes the new token and the event, and the
     * links the user already holds keep working.
     *
     * @throws DomainRuleException when a link went out under a minute ago
     */
    public function sendPasswordReset(User $user): bool
    {
        foreach ([self::RESET_BROKER, self::FIRST_LINK_BROKER] as $broker) {
            if (Password::broker($broker)->getRepository()->recentlyCreatedToken($user)) {
                throw new DomainRuleException('A password email was sent to this user less than a minute ago.', 429);
            }
        }

        try {
            DB::transaction(function () use ($user) {
                EventLogger::recordPlatform('platform.user.password_reset_sent', $user);
                Password::broker(self::FIRST_LINK_BROKER)->deleteToken($user);
                Password::broker(self::RESET_BROKER)->sendResetLink(['email' => $user->email]);
            });

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public function revokeAccess(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->clearAccess($user);

            EventLogger::recordPlatform('platform.user.access_revoked', $user);
        });
    }

    /**
     * Delete every token, pairing codes included, and unpair the WhatsApp
     * device, so a paired device cannot outlast the tokens. Records nothing:
     * the public reset calls this too, and no super admin acted there.
     */
    public function clearAccess(User $user): void
    {
        TenantContext::withoutScope(fn () => DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->whatsappDevice()->delete();
        }));
    }

    /**
     * Set a new password from an emailed link. Both kinds of link come here,
     * and both brokers are always tried on a failure, so an unknown email
     * takes as long as a wrong token and the response time doesn't reveal it.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation?: string}  $credentials
     */
    public function completeReset(array $credentials): bool
    {
        foreach ([self::RESET_BROKER, self::FIRST_LINK_BROKER] as $broker) {
            $status = Password::broker($broker)->reset($credentials, function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                    $this->clearAccess($user);
                });

                Password::broker(self::RESET_BROKER)->deleteToken($user);
                Password::broker(self::FIRST_LINK_BROKER)->deleteToken($user);
            });

            if ($status === Password::PASSWORD_RESET) {
                return true;
            }
        }

        return false;
    }

    private function refuseSuperAdminGrant(?string $role): void
    {
        if (self::refusesSuperAdminGrant($role)) {
            throw new DomainRuleException(self::SUPER_ADMIN_REFUSAL, 403);
        }
    }

    /**
     * The account a new user is attached to directly, if any. A group role
     * needs one, because accessibleHotelIds() ignores it without an account.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function accountForNewUser(Hotel $hotel, array $attributes): ?string
    {
        $accountId = $attributes['hotel_group_id'] ?? null;
        $groupRole = $attributes['group_role'] ?? null;

        if ($accountId !== null && $accountId !== $hotel->hotel_group_id) {
            throw new DomainRuleException('The selected hotel does not belong to the selected account.', 422);
        }

        if ($groupRole === null) {
            return $accountId;
        }

        if ($attributes['role'] !== UserRole::ADMIN->value) {
            throw new DomainRuleException('Only admins can be given an account-wide role.', 422);
        }

        return $hotel->hotel_group_id;
    }

    /**
     * Left in place, a former admin's ownership would block every later admin
     * from owning the hotel.
     */
    private function moveOwnershipWithRole(User $user, bool $wasAdmin): void
    {
        if ($wasAdmin) {
            Hotel::withTrashed()->where('owner_id', $user->getKey())->update(['owner_id' => null]);
        } elseif ($user->hotel_id) {
            $this->claimOwnership($user->hotel_id, $user);
        }
    }

    /**
     * A failed send leaves no token behind, so the operator's immediate retry
     * isn't throttled.
     */
    private function sendFirstLink(User $user): bool
    {
        $broker = Password::broker(self::FIRST_LINK_BROKER);

        try {
            $broker->sendResetLink(
                ['email' => $user->email],
                fn (User $recipient, string $token) => $recipient->notify(new UserInvitationNotification($token)),
            );

            return true;
        } catch (Throwable $e) {
            report($e);
            $broker->deleteToken($user);

            return false;
        }
    }

    /**
     * Only ever fills an empty owner, so an existing owner is never replaced.
     */
    private function claimOwnership(string $hotelId, User $user): void
    {
        Hotel::whereKey($hotelId)->whereNull('owner_id')->update(['owner_id' => $user->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function groupRoleAttributes(User $user, array $input, bool $isAdmin): array
    {
        $sent = array_key_exists('group_role', $input);
        $groupRole = $sent ? $input['group_role'] : $user->group_role;

        if (! $isAdmin) {
            if ($sent && $groupRole !== null) {
                throw new DomainRuleException('Only admins can be given an account-wide role.', 422);
            }

            return ['group_role' => null];
        }

        if ($groupRole === null) {
            return ['group_role' => null];
        }

        // accessibleHotelIds() ignores a group role with no account.
        $account = $user->account();

        if (! $account) {
            throw new DomainRuleException('This user does not belong to an account.', 422);
        }

        return ['group_role' => $groupRole, 'hotel_group_id' => $account->getKey()];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function staffRoleFor(User $user, array $input, string $role): ?string
    {
        if (array_key_exists('staff_role_id', $input)) {
            $hotel = $user->hotel_id ? Hotel::find($user->hotel_id) : null;

            if ($error = self::staffRoleError($input['staff_role_id'], $hotel, $role)) {
                throw new DomainRuleException($error['message'], $error['status']);
            }

            return $input['staff_role_id'];
        }

        // A user who stops being an employee loses their staff role, rather
        // than keeping one that would silently apply again on a demotion.
        return $role === UserRole::EMPLOYEE->value ? $user->staff_role_id : null;
    }

    /**
     * A team must belong to the user's home hotel, the same rule as the tenant
     * user endpoint. Null clears it.
     */
    private function teamFor(User $user, ?string $teamId): ?string
    {
        $hotel = $user->hotel_id ? Hotel::find($user->hotel_id) : null;

        if ($teamId !== null && (! $hotel || invalidRelation($hotel, ['teams' => $teamId]))) {
            throw new DomainRuleException('The selected team does not belong to you.', 403);
        }

        return $teamId;
    }

    /**
     * Replaces rows for live hotels only. A membership in a soft-deleted hotel
     * stays, so restoring the hotel returns the user's access. The home hotel
     * isn't a membership, and stays reachable whatever this list says.
     *
     * Only additions are checked against the account, so access can always be
     * narrowed, even for a user whose account can't be resolved.
     *
     * @param  list<string>  $hotelIds
     */
    private function replaceMemberships(User $user, array $hotelIds, string $role): void
    {
        $current = $user->hotels()->pluck('hotels.id')->all();
        $added = array_values(array_diff(array_unique($hotelIds), $current));
        $account = $user->account();

        $inAccount = $account
            ? Hotel::whereIn('id', $added)->where('hotel_group_id', $account->getKey())->count()
            : 0;

        if ($inAccount !== count($added)) {
            throw new DomainRuleException('A user can only be a member of hotels in their own account.', 422);
        }

        $user->hotels()->detach(array_diff($current, $hotelIds));

        foreach ($added as $hotelId) {
            // hotel_user has a uuid key with no default, and no pivot model.
            $user->hotels()->attach($hotelId, ['id' => (string) Str::uuid(), 'role' => $role, 'is_primary' => false]);
        }

        $user->unsetRelation('hotels');
    }

    /**
     * @return list<string>
     */
    private function liveMembershipIds(User $user): array
    {
        return $user->hotels()->orderBy('hotels.id')->pluck('hotels.id')->all();
    }

    /**
     * @return array<string, array{from: null, to: mixed}>
     */
    private function createdChanges(User $user): array
    {
        $changes = [];

        foreach (['name', 'email', 'role', 'hotel_id', 'hotel_group_id', 'group_role'] as $field) {
            $changes[$field] = ['from' => null, 'to' => EventLogger::normalize($user->getAttribute($field))];
        }

        return $changes;
    }
}
