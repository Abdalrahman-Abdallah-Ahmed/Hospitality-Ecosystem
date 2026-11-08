<?php

use App\Enums\GroupRole;
use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Services\Superadmin\UserAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Spec 025: an account has at most one owner, who is the only admin able to
| manage other admins.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function aoOwner(HotelGroup $account, Hotel $home): User
{
    return spUser($home, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);
}

function aoSuperAdmin(): User
{
    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

function aoRegistration(string $email = 'alice@example.com'): array
{
    return [
        'name' => 'Alice Example',
        'email' => $email,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'hotel' => ['name' => 'Alice Hotel', 'city' => 'Cairo'],
    ];
}

function aoPlatformEvents(string $type): int
{
    return EventLog::query()->where('event_type', $type)->count();
}

// User Story 2: registering

it('makes a registering customer the owner of the account created for their hotel', function () {
    Notification::fake();

    $response = $this->withHeaders(apiHeaders())->postJson('/api/register', aoRegistration())->assertCreated();

    $hotel = Hotel::findOrFail($response->json('body.hotel.id'));
    $user = User::findOrFail($response->json('body.user.id'));

    $response->assertJsonPath('body.user.hotel_group_id', $hotel->hotel_group_id)
        ->assertJsonPath('body.user.group_role', 'owner');

    expect($user->isOwner())->toBeTrue()
        ->and($user->group_role)->toBe(GroupRole::OWNER)
        ->and($user->hotel_id)->toBe($hotel->id);
});

it('gives a registered customer a hotel added to their account, with no further step', function () {
    Notification::fake();

    $id = $this->withHeaders(apiHeaders())->postJson('/api/register', aoRegistration())->json('body.user.id');
    $user = User::findOrFail($id);
    $second = Hotel::create(['hotel_group_id' => $user->hotel_group_id, 'name' => 'Second', 'slug' => 'second', 'currency' => 'USD']);

    spRequest($this, $user, 'GET', "/api/hotel/{$second->id}")->assertOk();
});

it('leaves nothing behind when a registration fails midway', function () {
    Notification::fake();

    Hotel::creating(fn () => throw new RuntimeException('Failed on purpose.'));

    $this->withHeaders(apiHeaders())->postJson('/api/register', aoRegistration())->assertServerError();

    expect(User::count())->toBe(0)
        ->and(Hotel::withTrashed()->count())->toBe(0)
        ->and(HotelGroup::withTrashed()->count())->toBe(0);
});

// User Story 3: one owner

it('refuses a second owner through the access endpoint, changing nothing', function () {
    [$account, $a] = spAccount();
    aoOwner($account, $a);
    $admin = spUser($a);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$admin->id}/access", ['group_role' => 'owner'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This account already has an owner.');

    expect($admin->fresh()->group_role)->toBeNull()
        ->and($admin->fresh()->hotel_group_id)->toBeNull()
        ->and(aoPlatformEvents('platform.user.access_changed'))->toBe(0);
});

it('refuses a second owner when creating a user, creating nothing and sending nothing', function () {
    Notification::fake();
    [$account, $a] = spAccount();
    aoOwner($account, $a);

    spRequest($this, aoSuperAdmin(), 'POST', '/api/superadmin/users', [
        'name' => 'Second', 'email' => 'second@example.com', 'role' => 'admin', 'hotel_id' => $a->id, 'group_role' => 'owner',
    ])->assertStatus(422)->assertJsonPath('message', 'This account already has an owner.');

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse()
        ->and(aoPlatformEvents('platform.user.created'))->toBe(0);

    Notification::assertNothingSent();
});

it('accepts owner for the current owner, with no change and no event', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$owner->id}/access", ['group_role' => 'owner'])->assertOk();

    expect($owner->fresh()->isOwner())->toBeTrue()
        ->and(aoPlatformEvents('platform.user.access_changed'))->toBe(0);
});

it('frees the account for a new owner once its owner is deleted', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $admin = spUser($a);
    $superAdmin = aoSuperAdmin();

    spRequest($this, $superAdmin, 'DELETE', "/api/users/{$owner->id}")->assertOk();
    spRequest($this, $superAdmin, 'PUT', "/api/superadmin/users/{$admin->id}/access", ['group_role' => 'owner'])->assertOk();

    expect($admin->fresh()->isOwner())->toBeTrue();
});

it('refuses the removed group admin role on both super admin endpoints', function () {
    [, $a] = spAccount();
    $admin = spUser($a);
    $superAdmin = aoSuperAdmin();

    spRequest($this, $superAdmin, 'PUT', "/api/superadmin/users/{$admin->id}/access", ['group_role' => 'group_admin'])
        ->assertStatus(422)->assertJsonValidationErrors(['group_role']);

    spRequest($this, $superAdmin, 'POST', '/api/superadmin/users', [
        'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin', 'hotel_id' => $a->id, 'group_role' => 'group_admin',
    ])->assertStatus(422)->assertJsonValidationErrors(['group_role']);
});

it('cannot store two owners for one account, whatever writes them', function () {
    [$account, $a] = spAccount();
    aoOwner($account, $a);

    expect(fn () => DB::transaction(fn () => User::factory()->role(UserRole::ADMIN)->create([
        'hotel_group_id' => $account->id, 'group_role' => 'owner',
    ])))->toThrow(QueryException::class, 'users_one_owner_per_account');
});

it('lets any number of users share an account with no role', function () {
    [$account] = spAccount();

    User::factory()->count(2)->role(UserRole::ADMIN)->create(['hotel_group_id' => $account->id]);

    expect(User::where('hotel_group_id', $account->id)->count())->toBe(2);
});

it('reports a duplicate email as its own failure, not as a second owner', function () {
    [, $a] = spAccount();
    spUser($a, attributes: ['email' => 'taken@example.com']);

    try {
        app(UserAdministration::class)->create(['name' => 'X', 'email' => 'taken@example.com', 'role' => 'admin', 'hotel_id' => $a->id]);
        $this->fail('The duplicate email was accepted.');
    } catch (DomainRuleException) {
        $this->fail('A duplicate email was reported as a second owner.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('users_email_unique');
    }
});

// User Story 5: only the owner manages other admins

it('refuses an admin who is not the owner changing or deleting another admin, or the owner', function (string $target, array $payload) {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $colleague = spUser($a);
    $admin = spUser($a);
    $victim = $target === 'owner' ? $owner : $colleague;
    $before = $victim->fresh()->only(['name', 'password', 'role']);

    spRequest($this, $admin, 'PUT', "/api/users/{$victim->id}", $payload)->assertForbidden();
    spRequest($this, $admin, 'DELETE', "/api/users/{$victim->id}")->assertForbidden();
    spRequest($this, $admin, 'GET', "/api/users/{$victim->id}")->assertOk();

    expect($victim->fresh()->only(['name', 'password', 'role']))->toEqual($before);
})->with([
    'the owner, a password' => ['owner', ['password' => 'Password123!', 'password_confirmation' => 'Password123!']],
    'the owner, a role' => ['owner', ['role' => 'employee']],
    'the owner, details' => ['owner', ['name' => 'Renamed']],
    'a colleague, a password' => ['colleague', ['password' => 'Password123!', 'password_confirmation' => 'Password123!']],
    'a colleague, a role' => ['colleague', ['role' => 'employee']],
    'a colleague, details' => ['colleague', ['name' => 'Renamed']],
]);

it('lets an admin who is not the owner edit and delete themselves, and manage employees', function () {
    [, $a] = spAccount();
    $admin = spUser($a);
    $employee = spUser($a, role: UserRole::EMPLOYEE);

    spRequest($this, $admin, 'PUT', "/api/users/{$admin->id}", ['name' => 'Me'])->assertOk();
    spRequest($this, $admin, 'PUT', "/api/users/{$employee->id}", ['name' => 'Staff'])->assertOk();
    spRequest($this, $admin, 'DELETE', "/api/users/{$employee->id}")->assertOk();
    spRequest($this, $admin, 'DELETE', "/api/users/{$admin->id}")->assertOk();

    expect(User::find($admin->id))->toBeNull()->and(User::find($employee->id))->toBeNull();
});

it('refuses an admin who is not the owner changing an employee who reaches a hotel the admin cannot', function () {
    [$account, $a, $b] = spAccount();
    aoOwner($account, $a);
    $admin = spUser($a);
    $employee = spUser($a, [$b], role: UserRole::EMPLOYEE);
    $before = $employee->fresh()->only(['name', 'password']);

    spRequest($this, $admin, 'PUT', "/api/users/{$employee->id}", ['password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertForbidden();
    spRequest($this, $admin, 'PUT', "/api/users/{$employee->id}", ['name' => 'Renamed'])->assertForbidden();
    spRequest($this, $admin, 'DELETE', "/api/users/{$employee->id}")->assertForbidden();
    spRequest($this, $admin, 'GET', "/api/users/{$employee->id}")->assertOk();

    expect($employee->fresh()->only(['name', 'password']))->toEqual($before);

    // An admin who reaches every hotel the employee does may change them.
    $wider = spUser($a, [$b]);

    spRequest($this, $wider, 'PUT', "/api/users/{$employee->id}", ['name' => 'Renamed'])->assertOk();
});

it('lets an admin view and edit themselves while working in a member hotel', function () {
    [, $a, $b] = spAccount();
    $admin = spUser($a, [$b]);

    spRequest($this, $admin, 'GET', "/api/users/{$admin->id}", headers: ['X-Hotel-Id' => $b->id])->assertOk();
    spRequest($this, $admin, 'PUT', "/api/users/{$admin->id}", ['name' => 'Me'], ['X-Hotel-Id' => $b->id])
        ->assertOk()->assertJsonPath('body.name', 'Me');
});

it('refuses an admin who is not the owner creating or promoting an admin', function () {
    [, $a] = spAccount();
    $admin = spUser($a);
    $employee = spUser($a, role: UserRole::EMPLOYEE);
    $message = 'Only the account owner can create or promote an admin.';

    spRequest($this, $admin, 'POST', '/api/users', [
        'name' => 'New', 'email' => 'new@example.com', 'password' => 'Password123!', 'role' => 'admin',
    ])->assertForbidden()->assertJsonPath('message', $message);

    spRequest($this, $admin, 'PUT', "/api/users/{$employee->id}", ['role' => 'admin'])
        ->assertForbidden()->assertJsonPath('message', $message);

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and($employee->fresh()->role)->toBe(UserRole::EMPLOYEE);

    spRequest($this, $admin, 'POST', '/api/users', [
        'name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'Password123!',
    ])->assertCreated();

    expect(User::where('email', 'staff@example.com')->sole()->role)->toBe(UserRole::EMPLOYEE);
});

it('lets an admin re-send their own existing role', function () {
    [, $a] = spAccount();
    $admin = spUser($a);

    spRequest($this, $admin, 'PUT', "/api/users/{$admin->id}", ['role' => 'admin', 'name' => 'Same'])->assertOk();
});

it('lets the owner create, promote, edit and delete other admins, without making them owners', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $employee = spUser($a, role: UserRole::EMPLOYEE);
    $colleague = spUser($a);

    $created = spRequest($this, $owner, 'POST', '/api/users', [
        'name' => 'New', 'email' => 'new@example.com', 'password' => 'Password123!', 'role' => 'admin',
    ])->assertCreated()->assertJsonPath('body.role', 'admin')->assertJsonPath('body.group_role', null);

    spRequest($this, $owner, 'PUT', "/api/users/{$employee->id}", ['role' => 'admin'])
        ->assertOk()->assertJsonPath('body.group_role', null);

    spRequest($this, $owner, 'PUT', "/api/users/{$colleague->id}", ['name' => 'Renamed', 'role' => 'employee'])
        ->assertOk()->assertJsonPath('body.role', 'employee');

    spRequest($this, $owner, 'DELETE', '/api/users/'.$created->json('body.id'))->assertOk();
});

it('lets the owner edit their own details, but not their role or their existence', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, $owner, 'PUT', "/api/users/{$owner->id}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('body.name', 'Renamed');

    spRequest($this, $owner, 'PUT', "/api/users/{$owner->id}", ['role' => 'admin'])->assertOk();
    spRequest($this, $owner, 'PUT', "/api/users/{$owner->id}", ['role' => 'employee'])->assertForbidden();
    spRequest($this, $owner, 'DELETE', "/api/users/{$owner->id}")->assertForbidden();

    expect($owner->fresh()->role)->toBe(UserRole::ADMIN)
        ->and($owner->fresh()->isOwner())->toBeTrue();
});

it('ends the ownership when a super admin changes an owner\'s role on the tenant endpoint', function () {
    [$account, $a, $b] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/users/{$owner->id}", ['role' => 'employee'])->assertOk();

    $fresh = $owner->fresh();
    $event = EventLog::query()->where('event_type', 'platform.user.access_changed')->sole();

    expect($event->changes['group_role'])->toBe(['from' => 'owner', 'to' => null])
        ->and($event->changes['hotel_group_id'])->toBe(['from' => $account->id, 'to' => null])
        ->and($event->changes['role'])->toBe(['from' => 'admin', 'to' => 'employee'])
        ->and($fresh->group_role)->toBeNull()
        ->and($fresh->hotel_group_id)->toBeNull()
        ->and($fresh->accessibleHotelIds())->toBe([$a->id])
        ->and($fresh->accessibleHotelIds())->not->toContain($b->id);
});

it('lets a super admin delete an owner on the tenant endpoint', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, aoSuperAdmin(), 'DELETE', "/api/users/{$owner->id}")->assertOk();

    expect(User::find($owner->id))->toBeNull();
});

it('ignores account attributes on the tenant endpoint for every caller', function () {
    [$account, $a] = spAccount();
    $employee = spUser($a, role: UserRole::EMPLOYEE);

    foreach ([spUser($a), aoSuperAdmin()] as $caller) {
        spRequest($this, $caller, 'PUT', "/api/users/{$employee->id}", [
            'hotel_group_id' => $account->id, 'group_role' => 'owner', 'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('body.name', 'Renamed');

        expect($employee->fresh()->group_role)->toBeNull()
            ->and($employee->fresh()->hotel_group_id)->toBeNull();
    }
});

// User Story 4: the operator decides

it('never makes the first admin of an account its owner', function () {
    Notification::fake();
    [, $a, $b] = spAccount();

    $id = spRequest($this, aoSuperAdmin(), 'POST', '/api/superadmin/users', [
        'name' => 'First', 'email' => 'first@example.com', 'role' => 'admin', 'hotel_id' => $a->id,
    ])->assertCreated()->assertJsonPath('body.group_role', null)->json('body.id');

    $user = User::findOrFail($id);

    expect($user->isOwner())->toBeFalse()
        ->and($user->accessibleHotelIds())->toBe([$a->id])
        ->and($user->accessibleHotelIds())->not->toContain($b->id);
});

it('never makes a promoted employee an owner', function () {
    [, $a] = spAccount();
    $employee = spUser($a, role: UserRole::EMPLOYEE);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$employee->id}/access", ['role' => 'admin'])
        ->assertOk()->assertJsonPath('body.group_role', null);

    expect($employee->fresh()->accessibleHotelIds())->toBe([$a->id]);
});

it('ends an ownership and its account link, recording both', function (array $payload) {
    [$account, $a, $b] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$owner->id}/access", $payload)->assertOk();

    $fresh = $owner->fresh();
    $changes = EventLog::query()->where('event_type', 'platform.user.access_changed')->sole()->changes;

    expect($fresh->group_role)->toBeNull()
        ->and($fresh->hotel_group_id)->toBeNull()
        ->and($fresh->account()->id)->toBe($account->id)
        ->and($fresh->accessibleHotelIds())->toBe([$a->id])
        ->and($fresh->accessibleHotelIds())->not->toContain($b->id)
        ->and($changes)->toHaveKeys(['group_role', 'hotel_group_id']);
})->with([
    'clearing the role' => [['group_role' => null]],
    'demoting them' => [['role' => 'employee']],
]);

it('clears a direct account link held with no role', function () {
    [$account, $a] = spAccount();
    $user = spUser($a, attributes: ['hotel_group_id' => $account->id]);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$user->id}/access", ['group_role' => null])->assertOk();

    expect($user->fresh()->hotel_group_id)->toBeNull();
});

it('keeps a direct account link when a change does not touch the role', function () {
    [$account, $a] = spAccount();
    $user = spUser($a, role: UserRole::EMPLOYEE, attributes: ['hotel_group_id' => $account->id]);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$user->id}/access", ['hotel_ids' => []])->assertOk();

    expect($user->fresh()->hotel_group_id)->toBe($account->id);
});

it('keeps an account with no owner working', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $admin = spUser($a);

    spRequest($this, aoSuperAdmin(), 'PUT', "/api/superadmin/users/{$owner->id}/access", ['group_role' => null])->assertOk();

    spRequest($this, $admin, 'GET', "/api/hotel/{$a->id}")->assertOk();
    spRequest($this, $admin, 'GET', '/api/guest')->assertOk();
    spRequest($this, $admin, 'GET', '/api/usage')->assertOk();
});

it('replaces an owner by clearing one and then assigning the other', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $next = spUser($a);
    $superAdmin = aoSuperAdmin();

    spRequest($this, $superAdmin, 'PUT', "/api/superadmin/users/{$owner->id}/access", ['group_role' => null])->assertOk();
    spRequest($this, $superAdmin, 'PUT', "/api/superadmin/users/{$next->id}/access", ['group_role' => 'owner'])->assertOk();

    expect($next->fresh()->isOwner())->toBeTrue()->and($owner->fresh()->isOwner())->toBeFalse();
});

// User Story 7: hotels no longer name an owner

it('shows no owner on any hotel response', function () {
    Notification::fake();
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);
    $superAdmin = aoSuperAdmin();

    $responses = [
        spRequest($this, $owner, 'GET', '/api/hotel'),
        spRequest($this, $owner, 'GET', "/api/hotel/{$a->id}"),
        spRequest($this, $owner, 'GET', '/api/user'),
        spRequest($this, $owner, 'GET', '/api/users'),
        spRequest($this, $superAdmin, 'GET', '/api/superadmin/hotels'),
        spRequest($this, $superAdmin, 'GET', "/api/superadmin/hotels/{$a->id}"),
        $this->withHeaders(apiHeaders())->postJson('/api/register', aoRegistration()),
    ];

    $owner->update(['password' => 'secret-password']);
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $responses[] = $this->withHeaders(apiHeaders())->postJson('/api/login', ['email' => $owner->email, 'password' => 'secret-password']);

    foreach ($responses as $index => $response) {
        expect($response->getStatusCode())->toBeLessThan(300, "Response {$index}: ".substr($response->getContent(), 0, 300))
            ->and($response->getContent())
            ->not->toContain('owner_id')
            ->not->toContain('"owner":');
    }
});

it('refuses the removed owner column on hotel lists, and ignores it on a write', function () {
    [$account, $a] = spAccount();
    $owner = aoOwner($account, $a);

    spRequest($this, $owner, 'GET', '/api/hotel?filter[owner_id]='.Str::uuid())
        ->assertStatus(422)->assertJsonValidationErrors(['filter.owner_id']);
    spRequest($this, $owner, 'GET', '/api/hotel?sort=owner_id')
        ->assertStatus(422)->assertJsonValidationErrors(['sort']);
    spRequest($this, aoSuperAdmin(), 'GET', '/api/superadmin/hotels?filter[owner_id]='.Str::uuid())->assertStatus(422);

    spRequest($this, $owner, 'PUT', "/api/hotel/{$a->id}", ['owner_id' => (string) Str::uuid(), 'city' => 'Cairo'])
        ->assertOk()->assertJsonPath('body.city', 'Cairo');
});
