<?php

namespace Database\Seeders;

use App\Enums\GroupRole;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\Superadmin\Suspension;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Accounts, hotels and users at every access level, for trying the app by hand:
 * php artisan db:seed --class=AccessLevelSeeder
 *
 * docs/test-accounts.md lists them. It is not part of DatabaseSeeder, because
 * every login here shares a well-known password, so it also refuses to run
 * outside local and testing. Safe to run again: it restores roles, hotels and
 * passwords, and leaves anything else you created alone.
 */
class AccessLevelSeeder extends Seeder
{
    public const PASSWORD = '123123123';

    private const SUSPENSION_REASON = 'Seeded for testing';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $logins = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('AccessLevelSeeder creates logins with a well-known password, so it only runs in local and testing.');
        }

        $this->call(PlatformSeeder::class);

        $superAdmin = User::where('email', PlatformSeeder::email())->firstOrFail();

        $this->logins[] = [$superAdmin->email, UserRole::SUPER_ADMIN->value, 'Platform super admin. Its password is SEED_SUPER_ADMIN_PASSWORD if that is set'];

        $this->user('second.superadmin@hospitality.test', 'Second Super Admin', UserRole::SUPER_ADMIN, null, note: 'Super admin, for ending another super admin\'s session');
        $this->user('unassigned.admin@hospitality.test', 'Unassigned Admin', UserRole::ADMIN, null, note: 'Admin with no hotel or account');

        $this->nileResorts();
        $redSea = $this->redSeaHotels();
        $delta = $this->deltaStays();
        $oasis = $this->oasisInn();

        // Last, so the sample data above could still be written.
        $suspension = app(Suspension::class);

        if (! $redSea->isSuspended()) {
            $suspension->suspendHotel($redSea, self::SUSPENSION_REASON, $superAdmin);
        }

        if (! $delta->isSuspended()) {
            $suspension->suspendAccount($delta, self::SUSPENSION_REASON, $superAdmin);
        }

        if (! $oasis->isSuspended()) {
            $suspension->suspendHotel($oasis, self::SUSPENSION_REASON, $superAdmin);
        }

        $this->command?->table(['Email', 'Access', 'Note'], array_map(
            fn (array $login) => [$login[0], $login[1], $login[2]],
            $this->logins,
        ));
        $this->command?->info('Password for these logins: '.self::PASSWORD);
    }

    /**
     * An active account with three hotels and every kind of staff.
     */
    private function nileResorts(): void
    {
        $account = $this->account('Nile Resorts', 'nile-resorts');
        $cairo = $this->hotel($account, 'Nile Cairo', 'nile-cairo', 'Cairo');
        $luxor = $this->hotel($account, 'Nile Luxor', 'nile-luxor', 'Luxor');
        $aswan = $this->hotel($account, 'Nile Aswan', 'nile-aswan', 'Aswan');

        $this->user('owner@nile.test', 'Nour Owner', UserRole::ADMIN, $cairo, account: $account, note: 'Account owner: every Nile hotel');
        $this->user('admin.multi@nile.test', 'Mona Multi', UserRole::ADMIN, $cairo, others: [$luxor], note: 'Admin: Cairo (default) and Luxor');

        $single = $this->user('admin.single@nile.test', 'Sami Single', UserRole::ADMIN, $aswan, phone: '201000000101', note: 'Admin: Aswan only, with a paired WhatsApp device');

        WhatsAppDevice::firstOrCreate(['wa_user_id' => 'seed-sami'], [
            'user_id' => $single->id,
            'phone_number' => '201000000101',
            'hotel_id' => $aswan->id,
            'status' => 'active',
        ]);

        $frontDesk = $this->staffRole($cairo, 'Front Desk', [
            Permission::DASHBOARD_VIEW,
            Permission::AVAILABILITY_VIEW,
            Permission::GUESTS_VIEW, Permission::GUESTS_CREATE, Permission::GUESTS_UPDATE,
            Permission::RESERVATIONS_VIEW, Permission::RESERVATIONS_CREATE, Permission::RESERVATIONS_UPDATE,
            Permission::STAYS_VIEW, Permission::STAYS_CHECK_IN, Permission::STAYS_CHECK_OUT,
            Permission::ROOMS_VIEW,
            Permission::BOOKINGS_VIEW,
            Permission::HISTORY_VIEW,
        ]);
        $housekeeping = $this->staffRole($cairo, 'Housekeeping', [
            Permission::ROOMS_VIEW, Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS,
            Permission::TASKS_VIEW, Permission::TASKS_UPDATE,
        ]);
        $viewer = $this->staffRole($cairo, 'Read Only', [
            Permission::GUESTS_VIEW,
            Permission::RESERVATIONS_VIEW,
            Permission::ROOMS_VIEW,
            Permission::HISTORY_VIEW,
        ]);

        $this->user('frontdesk@nile.test', 'Farah Desk', UserRole::EMPLOYEE, $cairo, role: $frontDesk, note: 'Employee, Front Desk role: Cairo');
        $this->user('frontdesk.multi@nile.test', 'Fadi Desk', UserRole::EMPLOYEE, $cairo, others: [$luxor], role: $frontDesk, note: 'Employee, Front Desk role: Cairo and Luxor at once');
        $this->user('housekeeping@nile.test', 'Hana Keeping', UserRole::EMPLOYEE, $cairo, role: $housekeeping, team: $cairo->housekeeping_team_id, note: 'Employee, Housekeeping role and team: Cairo');
        $this->user('readonly@nile.test', 'Rami Reader', UserRole::EMPLOYEE, $cairo, role: $viewer, note: 'Employee, read-only role: Cairo');
        $this->user('employee.norole@nile.test', 'Nabil Default', UserRole::EMPLOYEE, $cairo, note: 'Employee with no role: default permissions only');
    }

    /**
     * An active account with one hotel suspended, which is the default hotel of
     * two of its users. Returns that hotel.
     */
    private function redSeaHotels(): Hotel
    {
        $account = $this->account('Red Sea Hotels', 'red-sea-hotels');
        $hurghada = $this->hotel($account, 'Red Sea Hurghada', 'red-sea-hurghada', 'Hurghada');
        $dahab = $this->hotel($account, 'Red Sea Dahab', 'red-sea-dahab', 'Dahab');

        $role = $this->staffRole($hurghada, 'Front Desk', [Permission::GUESTS_VIEW, Permission::GUESTS_CREATE, Permission::RESERVATIONS_VIEW]);

        $this->user('owner@redsea.test', 'Rana Owner', UserRole::ADMIN, $dahab, account: $account, note: 'Account owner: Hurghada is suspended, Dahab is active');
        $this->user('admin@redsea.test', 'Adel Partial', UserRole::ADMIN, $hurghada, others: [$dahab], note: 'Admin: default hotel suspended, Dahab active');
        $this->user('employee@redsea.test', 'Eman Partial', UserRole::EMPLOYEE, $hurghada, others: [$dahab], role: $role, note: 'Employee: default hotel suspended, Dahab active');

        return $hurghada;
    }

    /**
     * A suspended account. Returns the account.
     */
    private function deltaStays(): HotelGroup
    {
        $account = $this->account('Delta Stays', 'delta-stays');
        $hotel = $this->hotel($account, 'Delta Mansoura', 'delta-mansoura', 'Mansoura');

        $this->user('owner@delta.test', 'Dina Owner', UserRole::ADMIN, $hotel, account: $account, note: 'Account suspended: login refused, impersonation can read');
        $this->user('employee@delta.test', 'Daoud Staff', UserRole::EMPLOYEE, $hotel, note: 'Account suspended: login refused');

        return $account;
    }

    /**
     * An active account whose only hotel is suspended. Returns the hotel.
     */
    private function oasisInn(): Hotel
    {
        $account = $this->account('Oasis Inn', 'oasis-inn');
        $hotel = $this->hotel($account, 'Oasis Siwa', 'oasis-siwa', 'Siwa');

        $this->user('admin@oasis.test', 'Omar Oasis', UserRole::ADMIN, $hotel, note: 'Only hotel suspended: login refused, impersonation can read');

        return $hotel;
    }

    private function account(string $name, string $slug): HotelGroup
    {
        return HotelGroup::updateOrCreate(['slug' => $slug], [
            'name' => $name,
            'country_code' => 'EG',
            'default_currency' => 'USD',
            'default_timezone' => 'Africa/Cairo',
        ]);
    }

    /**
     * A hotel with enough in it to look at: two rooms, a guest and a reservation.
     */
    private function hotel(HotelGroup $account, string $name, string $slug, string $city): Hotel
    {
        $hotel = Hotel::updateOrCreate(['slug' => $slug], [
            'hotel_group_id' => $account->id,
            'name' => $name,
            'timezone' => 'Africa/Cairo',
            'currency' => 'USD',
            'country_code' => 'EG',
            'city' => $city,
            'is_active' => true,
        ]);

        $roomType = RoomType::resolveFor($hotel->id);

        foreach (['101', '102'] as $number) {
            Room::firstOrCreate(['hotel_id' => $hotel->id, 'room_number' => $number], ['room_type_id' => $roomType->id, 'floor' => 1]);
        }

        $guest = Guest::firstOrCreate(['hotel_id' => $hotel->id, 'email' => "guest@{$slug}.test"], [
            'first_name' => 'Sample',
            'last_name' => $city,
            'preferred_language' => 'en',
        ]);

        $reference = Str::upper($slug).'-1001';

        if (! Reservation::where('hotel_id', $hotel->id)->where('reservation_id', $reference)->exists()) {
            ReservationCreator::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'reservation_id' => $reference,
                'arrival_date' => now()->addWeek(),
                'departure_date' => now()->addWeek()->addDays(3),
                'status' => ReservationStatus::CONFIRMED,
                'adults' => 2,
                'children' => 0,
                'source' => 'direct',
                'reservation_value' => 300,
                'currency' => $hotel->currency,
            ], [
                ['room_type_id' => $roomType->id],
            ]);
        }

        return $hotel->fresh();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function staffRole(Hotel $hotel, string $name, array $permissions): StaffRole
    {
        return StaffRole::updateOrCreate(['hotel_id' => $hotel->id, 'name' => $name], [
            'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
        ]);
    }

    /**
     * @param  list<Hotel>  $others  hotels they reach besides their default one
     * @param  HotelGroup|null  $account  makes them this account's owner
     */
    private function user(
        string $email,
        string $name,
        UserRole $access,
        ?Hotel $home,
        string $note,
        array $others = [],
        ?StaffRole $role = null,
        ?string $team = null,
        ?HotelGroup $account = null,
        ?string $phone = null,
    ): User {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => self::PASSWORD,
            'role' => $access,
            'hotel_id' => $home?->id,
            'hotel_group_id' => $account?->id,
            'group_role' => $account ? GroupRole::OWNER : null,
            'staff_role_id' => $role?->id,
            'team_id' => $team,
            'phone_number' => $phone,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        foreach ($others as $hotel) {
            if (! $user->hotels()->whereKey($hotel->id)->exists()) {
                $user->hotels()->attach($hotel->id, ['id' => (string) Str::uuid(), 'role' => $access->value, 'is_primary' => false]);
            }
        }

        $this->logins[] = [$email, $access->value, $note];

        return $user;
    }
}
