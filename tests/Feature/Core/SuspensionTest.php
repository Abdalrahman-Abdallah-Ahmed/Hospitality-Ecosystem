<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\StaffRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/*
| Spec 024: what a suspended customer's users get from the API.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function spLogin($test, User $user, string $password = 'secret-password')
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return $test->withHeaders(apiHeaders())->postJson('/api/login', ['email' => $user->email, 'password' => $password]);
}

function spLoginUser(Hotel $default, array $others = [], UserRole $role = UserRole::ADMIN, array $attributes = []): User
{
    return spUser($default, $others, $role, ['password' => Hash::make('secret-password'), ...$attributes]);
}

/**
 * @return array<string, mixed> the tags on the current Sentry scope
 */
function spSentryTags(): array
{
    $tags = [];

    Sentry\configureScope(function (Scope $scope) use (&$tags) {
        $tags = $scope->applyToEvent(Event::createEvent())?->getTags() ?? [];
    });

    return $tags;
}

// User Story 1: stop an account

it('refuses an existing token on the very next request, then lets it log out', function () {
    [$account, $hotel] = spAccount();
    $user = spUser($hotel, role: UserRole::EMPLOYEE);
    $token = $user->createToken('api')->plainTextToken;
    $headers = [...apiHeaders(), 'Authorization' => "Bearer {$token}"];

    $this->getJson('/api/user', $headers)->assertOk();

    spSuspend($account);
    app('auth')->forgetGuards();

    $this->getJson('/api/user', $headers)
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'This account has been suspended.',
            'code' => 403,
            'body' => ['reason' => 'suspended', 'scope' => 'account'],
        ]);

    app('auth')->forgetGuards();

    $this->postJson('/api/logout', [], $headers)->assertOk();
});

it('refuses login only after the password, and issues no token', function () {
    [$account, $hotel] = spAccount();
    $user = spLoginUser($hotel);
    spSuspend($account);

    spLogin($this, $user, 'wrong-password')->assertUnauthorized()->assertJsonPath('body', null);

    spLogin($this, $user)
        ->assertForbidden()
        ->assertJsonPath('body.reason', 'suspended')
        ->assertJsonPath('body.scope', 'account');

    expect($user->tokens()->count())->toBe(0);
});

it('never checks a super admin at login, and refuses their own token on hotel routes', function () {
    [$account, $hotel] = spAccount();
    spSuspend($account);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create(['password' => Hash::make('secret-password')]);

    spLogin($this, $superAdmin)->assertOk();

    spRequest($this, $superAdmin, 'GET', "/api/superadmin/hotels/{$hotel->id}")->assertOk();
    assertSuperAdminRefused(spRequest($this, $superAdmin, 'GET', "/api/hotel/{$hotel->id}"));
    assertSuperAdminRefused(spRequest($this, $superAdmin, 'PUT', "/api/hotel/{$hotel->id}", ['city' => 'Cairo']));
    expect($hotel->fresh()->city)->not->toBe('Cairo');
});

// User Story 2: reactivate

it('restores the old token and login, with the records unchanged', function () {
    [$account, $hotel] = spAccount();
    $user = spLoginUser($hotel);
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Front desk']);
    $headers = [...apiHeaders(), 'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];

    spSuspend($account);
    $this->getJson('/api/user', $headers)->assertForbidden();

    spReactivate($account);
    app('auth')->forgetGuards();

    $this->getJson('/api/user', $headers)->assertOk();
    spLogin($this, $user)->assertOk();

    expect(Team::withoutGlobalScope('hotel')->find($team->id))->not->toBeNull();
});

it('keeps a hotel suspended when restored, and follows its account when moved or created', function () {
    [$account, $hotel] = spAccount();
    spSuspend($hotel);
    $hotel->delete();
    $hotel->restore();

    expect($hotel->fresh()->isSuspended())->toBeTrue();

    [$stopped] = spAccount('stopped');
    [, $moved] = spAccount('running');
    spSuspend($stopped);
    $moved->update(['hotel_group_id' => $stopped->id]);

    expect($moved->fresh()->isSuspended())->toBeTrue();

    $created = Hotel::create(['hotel_group_id' => $stopped->id, 'name' => 'New', 'slug' => 'new']);
    $user = spUser($created);

    expect($created->fresh()->isSuspended())->toBeTrue();
    spRequest($this, $user, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'account');
});

// User Story 3: one hotel of a multi-hotel user

it('moves a user whose default hotel is suspended to their active one, at login and on /user', function () {
    [, $a, $b] = spAccount();
    $user = spLoginUser($a, [$b]);
    spSuspend($a);

    spLogin($this, $user)
        ->assertOk()
        ->assertJsonPath('body.user.hotel.id', $b->id)
        ->assertJsonPath('body.user.hotel_id', $a->id);

    spRequest($this, $user->fresh(), 'GET', '/api/user')
        ->assertOk()
        ->assertJsonPath('body.hotel.id', $b->id)
        ->assertJsonPath('body.hotel_id', $a->id);

    expect($user->fresh()->hotel_id)->toBe($a->id);
});

it('acts on the active hotel, with no header and when naming it', function (bool $withHeader) {
    [, $a, $b] = spAccount();
    $user = spUser($a, [$b]);
    $teamB = Team::create(['hotel_id' => $b->id, 'name' => 'In B']);
    $teamA = Team::create(['hotel_id' => $a->id, 'name' => 'In A']);
    spSuspend($a);
    $headers = $withHeader ? ['X-Hotel-Id' => $b->id] : [];

    spRequest($this, $user, 'GET', '/api/dashboard', headers: $headers)->assertOk();

    // A policy-guarded read compares the record with $user->hotel.
    spRequest($this, $user, 'GET', "/api/team/{$teamB->id}", headers: $headers)->assertOk();

    $created = spRequest($this, $user, 'POST', '/api/team', ['name' => 'New', 'hotel_id' => $a->id], $headers)->assertCreated();
    expect($created->json('body.hotel_id') ?? Team::withoutGlobalScope('hotel')->find($created->json('body.id'))->hotel_id)->toBe($b->id);

    // A record of the suspended hotel is simply not found.
    spRequest($this, $user, 'GET', "/api/team/{$teamA->id}", headers: $headers)->assertNotFound();

    expect($user->fresh()->hotel_id)->toBe($a->id);
})->with(['no header' => false, 'naming the active hotel' => true]);

it('refuses a request naming the suspended hotel', function () {
    [, $a, $b] = spAccount();
    $user = spUser($a, [$b]);
    spSuspend($a);

    spRequest($this, $user, 'GET', '/api/user', headers: ['X-Hotel-Id' => $a->id])
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'This hotel has been suspended.',
            'code' => 403,
            'body' => ['reason' => 'suspended', 'scope' => 'hotel'],
        ]);
});

it('works unchanged when another of the user\'s hotels is suspended', function () {
    [, $a, $b] = spAccount();
    $user = spUser($a, [$b]);
    spSuspend($b);

    spRequest($this, $user, 'GET', '/api/user')->assertOk()->assertJsonPath('body.hotel.id', $a->id);
});

it('refuses a user whose hotels are all suspended, with scope hotel', function () {
    [, $a, $b] = spAccount();
    $user = spLoginUser($a, [$b]);
    spSuspend($a);
    spSuspend($b);

    spLogin($this, $user)->assertForbidden()->assertJsonPath('body.scope', 'hotel');
    spRequest($this, $user, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'hotel');
});

it('keeps an assigned employee working in the other hotel with their role', function () {
    [, $a, $b] = spAccount();
    $role = StaffRole::create([
        'hotel_id' => $a->id,
        'name' => 'Desk',
        'permissions' => [Permission::TEAMS_VIEW->value],
    ]);
    $employee = spUser($a, [$b], UserRole::EMPLOYEE, ['staff_role_id' => $role->id]);
    Team::create(['hotel_id' => $b->id, 'name' => 'In B']);
    spSuspend($a);

    $teams = spRequest($this, $employee, 'GET', '/api/team')->assertOk();

    expect(collect($teams->json('body.data'))->pluck('id')->sort()->values()->all())
        ->toBe(Team::withoutGlobalScope('hotel')->where('hotel_id', $b->id)->pluck('id')->sort()->values()->all());

    spRequest($this, $employee, 'GET', '/api/user')
        ->assertOk()
        ->assertJsonPath('body.permissions', [Permission::TEAMS_VIEW->value]);
});

it('gives an employee of a suspended hotel no access to the account\'s other hotels unless assigned', function () {
    [, $a] = spAccount();
    $employee = spUser($a, role: UserRole::EMPLOYEE);
    spSuspend($a);

    spRequest($this, $employee, 'GET', '/api/user')
        ->assertForbidden()
        ->assertJsonPath('body.scope', 'hotel');
});

it('moves to the same hotel at login and on every request, whatever order the hotels come in', function () {
    [, $a, $b] = spAccount();
    $c = Hotel::create(['hotel_group_id' => $a->hotel_group_id, 'name' => 'C', 'slug' => 'acme-c', 'currency' => 'USD']);
    $user = spLoginUser($a, [$c, $b]);
    spSuspend($a);
    $expected = collect([$b->id, $c->id])->sort()->first();

    spLogin($this, $user)->assertOk()->assertJsonPath('body.user.hotel.id', $expected);
    spRequest($this, $user->fresh(), 'GET', '/api/user')->assertOk()->assertJsonPath('body.hotel.id', $expected);
});

it('shows the same team at login and on /user when the default hotel is suspended', function () {
    [, $a, $b] = spAccount();
    $team = Team::create(['hotel_id' => $a->id, 'name' => 'Desk']);
    $user = spLoginUser($a, [$b], attributes: ['team_id' => $team->id]);
    spSuspend($a);

    spLogin($this, $user)->assertOk()->assertJsonPath('body.user.team.id', $team->id);
    spRequest($this, $user->fresh(), 'GET', '/api/user')->assertOk()->assertJsonPath('body.team.id', $team->id);
});

it('tags a blocked request, and names the hotel a moved request ran in', function () {
    config(['sentry.dsn' => 'https://public@example.ingest.sentry.io/1']);
    [, $a, $b] = spAccount();
    $user = spUser($a, [$b]);
    spSuspend($a);

    $hub = SentrySdk::getCurrentHub();
    $hub->pushScope();

    try {
        spRequest($this, $user, 'GET', '/api/user')->assertOk();

        expect(spSentryTags()['hotel_id'] ?? null)->toBe($b->id)
            ->and(spSentryTags())->not->toHaveKey('suspended');

        spRequest($this, $user, 'GET', '/api/user', headers: ['X-Hotel-Id' => $a->id])->assertForbidden();

        expect(spSentryTags()['suspended'] ?? null)->toBe('true');
    } finally {
        $hub->popScope();
    }
});

// User Story 6: nothing leaks

it('cannot be undone through the tenant API', function () {
    [, $a] = spAccount();
    $owner = spUser($a);

    spRequest($this, $owner, 'PUT', "/api/hotel/{$a->id}", [
        'city' => 'Cairo',
        'suspended_at' => now()->toIso8601String(),
        'suspended_reason' => 'Self',
    ])->assertOk();

    expect($a->fresh())
        ->city->toBe('Cairo')
        ->suspended_at->toBeNull()
        ->suspended_reason->toBeNull();

    spSuspend($a);

    spRequest($this, $owner, 'PUT', "/api/hotel/{$a->id}", ['suspended_at' => null])->assertForbidden();

    expect($a->fresh()->suspended_at)->not->toBeNull();
});

it('tells a stranger nothing about a suspended hotel', function () {
    [, $suspended] = spAccount('stopped');
    [, $own] = spAccount('running');
    $stranger = spUser($own);
    spSuspend($suspended);

    foreach (['GET', 'PUT'] as $method) {
        $response = spRequest($this, $stranger, $method, "/api/hotel/{$suspended->id}", ['city' => 'Cairo'])->assertForbidden();

        expect($response->json('body.reason'))->toBeNull();
    }

    $response = spRequest($this, $stranger, 'GET', '/api/user', headers: ['X-Hotel-Id' => $suspended->id])->assertForbidden();

    expect($response->json('body.reason'))->toBeNull();
});

it('refuses a suspended hotel to its owner on its own routes, after authorization', function () {
    [$account, $a, $b] = spAccount();
    $owner = spUser($b, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);
    spSuspend($a);

    foreach (['GET', 'PUT', 'PATCH'] as $method) {
        spRequest($this, $owner, $method, "/api/hotel/{$a->id}", ['city' => 'Cairo'])
            ->assertForbidden()
            ->assertJsonPath('body.reason', 'suspended')
            ->assertJsonPath('body.scope', 'hotel');
    }

    expect($a->fresh()->city)->toBeNull();
});

it('still opens an active hotel to an owner with no membership', function () {
    [$account, $a, $b] = spAccount();
    $owner = spUser($b, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);

    spRequest($this, $owner, 'GET', "/api/hotel/{$a->id}")->assertOk();
});

it('shows suspension details on no tenant response', function () {
    [$account, $a, $b] = spAccount();
    $owner = spUser($a, [$b]);
    spSuspend($b);

    foreach (['/api/user', "/api/hotel/{$a->id}", '/api/hotel'] as $uri) {
        $content = spRequest($this, $owner, 'GET', $uri)->assertOk()->getContent();

        expect($content)->not->toContain('suspended_')->not->toContain('is_suspended');
    }

    expect($account->fresh()->isSuspended())->toBeFalse();
});

it('keeps suspension events off a hotel\'s event log', function () {
    [, $a] = spAccount();
    $admin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/superadmin/hotels/{$a->id}/suspend", ['reason' => 'Unpaid'])->assertOk();

    expect(EventLog::withoutGlobalScope('hotel')->where('hotel_id', $a->id)->where('event_type', 'like', 'platform.%')->exists())->toBeFalse();
});

it('does not tell a user with no hotels that they are suspended', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create(['password' => Hash::make('secret-password')]);

    spLogin($this, $user)->assertOk();
    spRequest($this, $user, 'GET', '/api/user')->assertOk();
});

it('refuses an owner with no hotels in a suspended account', function () {
    $account = HotelGroup::create(['name' => 'Empty', 'slug' => 'empty']);
    spSuspend($account);
    $owner = User::factory()->role(UserRole::ADMIN)->create([
        'hotel_group_id' => $account->id,
        'group_role' => 'owner',
        'password' => Hash::make('secret-password'),
    ]);

    spLogin($this, $owner)->assertForbidden()->assertJsonPath('body.scope', 'account');
    spRequest($this, $owner, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'account');
});

it('counts a suspended account that was soft-deleted', function () {
    $account = HotelGroup::create(['name' => 'Gone', 'slug' => 'gone']);
    spSuspend($account);
    $account->delete();
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $account->id]);

    spRequest($this, $owner, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'account');
});
