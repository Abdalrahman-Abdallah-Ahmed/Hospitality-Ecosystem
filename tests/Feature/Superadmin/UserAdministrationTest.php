<?php

use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Notifications\UserInvitationNotification;
use App\Services\SenderRecognitionService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/*
| Spec 023: user administration and reset access under /api/superadmin.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
});

function userAdmin($test)
{
    return $test->withHeaders(apiHeaders())
        ->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum');
}

function userAccount(string $slug = 'acme'): HotelGroup
{
    return HotelGroup::create(['name' => Str::title($slug), 'slug' => $slug]);
}

function userHotel(HotelGroup $account, string $slug = 'harbor', array $attributes = []): Hotel
{
    return Hotel::create(['hotel_group_id' => $account->id, 'name' => Str::title($slug), 'slug' => $slug, ...$attributes]);
}

function newUserPayload(Hotel $hotel, array $overrides = []): array
{
    return [
        'name' => 'Nadia Admin',
        'email' => 'nadia@example.com',
        'role' => 'admin',
        'hotel_id' => $hotel->id,
        ...$overrides,
    ];
}

function addMembership(User $user, Hotel $hotel): void
{
    DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'hotel_id' => $hotel->id, 'role' => $user->role->value]);
}

/**
 * The real channel is replaced for this one test, because a notification
 * fake cannot fail.
 */
function failMail(): void
{
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Mail provider is down'));
}

// Onboarding

it('onboards a customer from nothing to an admin opening their hotel, with no SQL', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $as = fn () => $this->withHeaders(apiHeaders())->actingAs($superAdmin, 'sanctum');

    $accountId = $as()->postJson('/api/superadmin/accounts', ['name' => 'Acme', 'slug' => 'acme'])
        ->assertCreated()->json('body.id');
    $hotelId = $as()->postJson("/api/superadmin/accounts/{$accountId}/hotels", ['name' => 'Harbor', 'slug' => 'harbor'])
        ->assertCreated()->json('body.id');
    $userId = $as()->postJson('/api/superadmin/users', [
        'name' => 'Nadia', 'email' => 'nadia@example.com', 'role' => 'admin', 'hotel_id' => $hotelId,
    ])->assertCreated()->assertJsonPath('body.reset_email_sent', true)->json('body.id');

    $admin = User::find($userId);
    expect(Hotel::find($hotelId)->owner_id)->toBe($userId);

    $token = null;
    Notification::assertSentTo($admin, UserInvitationNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    // Nobody is signed in from here on, as in the real journey.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    $this->withHeaders(apiHeaders())->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'nadia@example.com',
        'password' => 'A-new-Password1!',
        'password_confirmation' => 'A-new-Password1!',
    ])->assertOk();

    $apiToken = $this->withHeaders(apiHeaders())->postJson('/api/login', [
        'email' => 'nadia@example.com',
        'password' => 'A-new-Password1!',
    ])->assertOk()->json('body.token');

    app('auth')->forgetGuards();

    $this->withHeaders([...apiHeaders(), 'Authorization' => "Bearer {$apiToken}"])
        ->getJson("/api/hotel/{$hotelId}")
        ->assertOk()
        ->assertJsonPath('body.id', $hotelId);

    $events = EventLog::withoutGlobalScopes()->where('event_type', 'like', 'platform.%')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$superAdmin->id])
        ->and($events->pluck('hotel_id')->filter()->all())->toBe([]);
});

it('creates a user the super admin cannot log in as, and says the email was sent', function () {
    $hotel = userHotel(userAccount());

    $response = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel, ['password' => 'Chosen-By-Admin1!']))
        ->assertCreated()
        ->assertJsonPath('body.reset_email_sent', true)
        ->assertJsonPath('body.hotel_ids', []);

    $user = User::find($response->json('body.id'));

    expect($response->getContent())->not->toContain('Chosen-By-Admin1!')
        ->and(array_keys($response->json('body')))->not->toContain('password')
        ->and(Hash::check('Chosen-By-Admin1!', $user->password))->toBeFalse();

    Notification::assertSentTo($user, UserInvitationNotification::class);

    $events = EventLog::withoutGlobalScopes()->where('subject_id', $user->id)->get();

    expect($events->pluck('event_type')->all())->toBe(['platform.user.created'])
        ->and(json_encode($events->first()->changes))->not->toContain($user->password);
});

it('refuses a new user without a live home hotel, or with a hotel outside the account', function (Closure $payload) {
    $account = userAccount();
    $hotel = userHotel($account);
    $other = userAccount('other');
    $deleted = userHotel($account, 'gone');
    $deleted->delete();

    userAdmin($this)->postJson('/api/superadmin/users', $payload($hotel, $other, $deleted, $account))->assertStatus(422);

    expect(User::where('email', 'nadia@example.com')->exists())->toBeFalse();
})->with([
    'no hotel' => [fn ($hotel) => array_diff_key(newUserPayload($hotel), ['hotel_id' => 1])],
    'an account but no hotel' => [fn ($hotel, $other, $deleted, $account) => [...array_diff_key(newUserPayload($hotel), ['hotel_id' => 1]), 'hotel_group_id' => $account->id]],
    'a deleted hotel' => [fn ($hotel, $other, $deleted) => newUserPayload($deleted)],
    'a hotel outside the account' => [fn ($hotel, $other) => newUserPayload($hotel, ['hotel_group_id' => $other->id])],
    'an account-wide role on an employee' => [fn ($hotel) => newUserPayload($hotel, ['role' => 'employee', 'group_role' => 'owner'])],
    'an unknown account-wide role' => [fn ($hotel) => newUserPayload($hotel, ['group_role' => 'emperor'])],
    'an unknown role' => [fn ($hotel) => newUserPayload($hotel, ['role' => 'manager'])],
]);

it('attaches a new admin with an account-wide role to the hotel\'s account', function () {
    $account = userAccount();
    $hotel = userHotel($account);
    $second = userHotel($account, 'second');

    $id = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel, ['group_role' => 'owner']))
        ->assertCreated()
        ->assertJsonPath('body.hotel_group_id', $account->id)
        ->json('body.id');

    expect(User::find($id)->accessibleHotelIds())->toEqualCanonicalizing([$hotel->id, $second->id]);
});

it('makes a new admin the owner only when the hotel has none', function () {
    $hotel = userHotel(userAccount());

    $first = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel))->json('body.id');
    userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel, ['email' => 'second@example.com']))->assertCreated();

    expect($hotel->fresh()->owner_id)->toBe($first);
});

it('does not make a new employee the owner', function () {
    $hotel = userHotel(userAccount());

    userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel, ['role' => 'employee']))->assertCreated();

    expect($hotel->fresh()->owner_id)->toBeNull();
});

it('keeps the user and says so when the first email cannot be sent, and a resend is not throttled', function () {
    $hotel = userHotel(userAccount());
    failMail();

    $id = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel))
        ->assertCreated()
        ->assertJsonPath('body.reset_email_sent', false)
        ->json('body.id');

    expect(User::find($id))->not->toBeNull()
        ->and(DB::table('user_invitation_tokens')->count())->toBe(0);

    Notification::clearResolvedInstance(Dispatcher::class);
    Notification::fake();

    userAdmin($this)->postJson("/api/superadmin/users/{$id}/password-reset")->assertOk();
});

it('reports a failed first email to error monitoring', function () {
    $hotel = userHotel(userAccount());
    failMail();
    $reported = Exceptions::fake();

    userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel))->assertCreated();

    $reported->assertReported(RuntimeException::class);
});

// The super admin role

it('refuses role=super_admin on an otherwise valid create', function () {
    $hotel = userHotel(userAccount());

    userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel, ['role' => 'super_admin']))
        ->assertStatus(403)
        ->assertJsonPath('message', 'The super admin role cannot be assigned through the API.');

    expect(User::where('email', 'nadia@example.com')->exists())->toBeFalse();
});

it('answers 404 for a super admin target on every user endpoint', function (string $method, string $suffix, array $payload) {
    $target = User::factory()->role(UserRole::SUPER_ADMIN)->create(['name' => 'Keep']);

    userAdmin($this)->json($method, "/api/superadmin/users/{$target->id}{$suffix}", $payload)->assertNotFound();

    expect($target->fresh()->name)->toBe('Keep');
})->with([
    'view' => ['GET', '', []],
    'edit' => ['PUT', '', ['name' => 'Changed']],
    'access' => ['PUT', '/access', ['role' => 'admin']],
    'reset' => ['POST', '/password-reset', []],
    'revoke' => ['POST', '/revoke-tokens', []],
]);

// Lockout

it('locks out a compromised user: tokens, pairing code and device', function () {
    $hotel = userHotel(userAccount());
    $user = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id, 'phone_number' => '201000000001']);
    $user->createToken('api-token');
    $code = pairingCodeFor($user);
    WhatsAppDevice::create(['user_id' => $user->id, 'phone_number' => '201000000001', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-1', 'status' => 'active']);

    expect(app(SenderRecognitionService::class)->resolve('201000000001')->devicePaired)->toBeTrue();

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/revoke-tokens")->assertOk();

    expect($user->tokens()->count())->toBe(0)
        ->and(WhatsAppDevice::where('user_id', $user->id)->exists())->toBeFalse()
        // The Admin Advisor answers only a paired device.
        ->and(app(SenderRecognitionService::class)->resolve('201000000001')->devicePaired)->toBeFalse()
        ->and(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.access_revoked')->where('subject_id', $user->id)->count())->toBe(1);

    $this->withHeaders(apiHeaders())->postJson('/api/pair', [
        'token' => $code,
        'phone_number' => '201000000002',
        'wa_user_id' => 'wa-2',
    ])->assertStatus(201)->assertJsonPath('message', 'Invalid token.');
});

it('records a revoke even with nothing to revoke', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/revoke-tokens")->assertOk();

    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.access_revoked')->count())->toBe(1);
});

it('sends a reset email that opens the frontend, and records it', function () {
    config(['app.frontend_url' => 'https://app.example.com']);
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id, 'email' => 'someone+tag@example.com']);

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/password-reset")->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        expect($url)->toStartWith('https://app.example.com/reset-password?token='.$notification->token)
            ->and($url)->toContain('email=someone%2Btag%40example.com');

        return true;
    });

    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.password_reset_sent')->count())->toBe(1);
});

it('throttles a second reset email within a minute, also right after a create', function () {
    $hotel = userHotel(userAccount());
    $id = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel))->json('body.id');

    userAdmin($this)->postJson("/api/superadmin/users/{$id}/password-reset")->assertStatus(429);

    $this->travel(61)->seconds();

    userAdmin($this)->postJson("/api/superadmin/users/{$id}/password-reset")->assertOk();
    userAdmin($this)->postJson("/api/superadmin/users/{$id}/password-reset")->assertStatus(429);

    Notification::assertSentToTimes(User::find($id), ResetPassword::class, 1);
    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.password_reset_sent')->count())->toBe(1);
});

it('replaces a pending first link with a reset link once it is sent', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);
    $first = Password::broker('invitations')->createToken($user);
    $this->travel(61)->seconds();

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/password-reset")->assertOk();

    expect(Password::broker('invitations')->tokenExists($user, $first))->toBeFalse();
});

it('answers 503 and keeps a pending first link when a reset email fails', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);
    $first = Password::broker('invitations')->createToken($user);
    $this->travel(61)->seconds();
    failMail();

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/password-reset")->assertStatus(503);

    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.password_reset_sent')->count())->toBe(0)
        ->and(Password::broker('invitations')->tokenExists($user, $first))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

// The list

it('finds users attached by account, home hotel and membership, once each', function () {
    $account = userAccount();
    $hotel = userHotel($account);
    $second = userHotel($account, 'second');

    $byAccount = User::factory()->create(['hotel_group_id' => $account->id]);
    $byHome = User::factory()->create(['hotel_id' => $hotel->id]);
    $byMembership = User::factory()->create();
    addMembership($byMembership, $hotel);
    $byAll = User::factory()->create(['hotel_group_id' => $account->id, 'group_role' => 'owner', 'hotel_id' => $hotel->id]);
    addMembership($byAll, $hotel);
    $elsewhere = User::factory()->create(['hotel_id' => userHotel(userAccount('other'), 'far')->id]);

    $ids = fn (string $query) => collect(userAdmin($this)->getJson("/api/superadmin/users?{$query}")->assertOk()->json('body.data'))->pluck('id')->all();

    expect($ids("filter[hotel_group_id]={$account->id}"))
        ->toEqualCanonicalizing([$byAccount->id, $byHome->id, $byMembership->id, $byAll->id]);

    // A plain hotel_group_id without a role reaches no hotel, so the hotel
    // filter does not list $byAccount.
    expect($ids("filter[hotel_id]={$hotel->id}"))
        ->toEqualCanonicalizing([$byHome->id, $byMembership->id, $byAll->id]);

    expect($ids("filter[hotel_id]={$second->id}"))->toBe([$byAll->id]);
    expect($ids("filter[hotel_group_id]={$account->id}"))->not->toContain($elsewhere->id);
});

it('never lists a super admin, even when asked', function () {
    User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $employee = User::factory()->create();

    $all = userAdmin($this)->getJson('/api/superadmin/users')->assertOk()->json('body.data');

    expect(collect($all)->pluck('id')->all())->toBe([$employee->id])
        ->and(userAdmin($this)->getJson('/api/superadmin/users?filter[role]=super_admin')->assertOk()->json('body.data'))->toBeEmpty();
});

it('filters by role and includes memberships on each row', function () {
    $hotel = userHotel(userAccount());
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    addMembership($admin, $hotel);
    User::factory()->create();

    $rows = userAdmin($this)->getJson('/api/superadmin/users?filter[role]=admin')->assertOk()->json('body.data');

    expect($rows)->toHaveCount(1)->and($rows[0]['hotel_ids'])->toBe([$hotel->id]);
});

it('refuses a filter or sort on a secret column, and a malformed id', function (string $query) {
    userAdmin($this)->getJson('/api/superadmin/users?'.$query)->assertStatus(422);
})->with([
    'filter password' => 'filter[password]=x',
    'filter remember token' => 'filter[remember_token]=x',
    'sort password' => 'sort=password',
    'sort remember token' => 'sort=-remember_token',
    'malformed hotel' => 'filter[hotel_id]=not-a-uuid',
    'malformed account' => 'filter[hotel_group_id]=not-a-uuid',
    'malformed user id' => 'filter[id]=abc',
    'malformed team' => 'filter[team_id]=abc',
    'malformed staff role' => 'filter[staff_role_id]=abc',
    'malformed date' => 'filter[created_at]=abc',
]);

it('narrows memberships for a user whose account cannot be resolved', function () {
    $a = userHotel(userAccount('a'), 'a');
    $b = userHotel(userAccount('b'), 'b');
    $user = User::factory()->create();
    addMembership($user, $a);
    addMembership($user, $b);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['hotel_ids' => [$a->id]])
        ->assertOk()
        ->assertJsonPath('body.hotel_ids', [$a->id]);

    // Adding a hotel still needs an account to check it against.
    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['hotel_ids' => [$a->id, $b->id]])
        ->assertStatus(422);
});

it('keeps tokens and the device when the revoke event cannot be written', function () {
    $hotel = userHotel(userAccount());
    $user = User::factory()->create(['hotel_id' => $hotel->id]);
    $user->createToken('api-token');
    WhatsAppDevice::create(['user_id' => $user->id, 'phone_number' => '201000000011', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-11', 'status' => 'active']);
    $request = userAdmin($this);

    EventLog::creating(fn () => throw new RuntimeException('Audit is down'));

    $request->postJson("/api/superadmin/users/{$user->id}/revoke-tokens")->assertStatus(500);

    expect($user->tokens()->count())->toBe(1)
        ->and(WhatsAppDevice::where('user_id', $user->id)->exists())->toBeTrue();
});

it('sends no email when the reset event cannot be written', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);
    $request = userAdmin($this);

    EventLog::creating(fn () => throw new RuntimeException('Audit is down'));

    $request->postJson("/api/superadmin/users/{$user->id}/password-reset")->assertStatus(503);

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('keeps an earlier reset link working when a resend fails', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);
    $earlier = Password::broker('users')->createToken($user);
    $this->travel(2)->minutes();
    failMail();

    userAdmin($this)->postJson("/api/superadmin/users/{$user->id}/password-reset")->assertStatus(503);

    expect(Password::broker('users')->tokenExists($user, $earlier))->toBeTrue();
});

it('forgets pending links when the email changes, so changing it back does not revive them', function () {
    $user = User::factory()->create(['hotel_id' => userHotel(userAccount())->id, 'email' => 'a@example.com']);
    $reset = Password::broker('users')->createToken($user);
    $first = Password::broker('invitations')->createToken($user);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}", ['email' => 'b@example.com'])->assertOk();
    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}", ['email' => 'a@example.com'])->assertOk();

    $fresh = $user->fresh();

    expect(Password::broker('users')->tokenExists($fresh, $reset))->toBeFalse()
        ->and(Password::broker('invitations')->tokenExists($fresh, $first))->toBeFalse();
});

it('forgets pending links when the user is deleted, so a new user with that email cannot use them', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $token = Password::broker('invitations')->createToken($user);

    $user->delete();

    $next = User::factory()->create(['email' => 'gone@example.com']);

    expect(Password::broker('invitations')->tokenExists($next, $token))->toBeFalse();
});

it('keeps memberships out of tenant user responses', function () {
    $hotel = makeHotel();
    $staff = User::factory()->create(['hotel_id' => $hotel->id]);

    $body = $this->withHeaders(apiHeaders())->actingAs($hotel->owner, 'sanctum')
        ->getJson("/api/users/{$staff->id}")->assertOk()->json('body');

    expect($body)->not->toHaveKey('hotel_ids');
});

// Editing

it('edits only name, email and phone', function () {
    $hotel = userHotel(userAccount());
    $user = User::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Old']);
    $hash = $user->password;

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}", [
        'name' => 'New',
        'password' => 'Should-Be-Ignored1!',
        'role' => 'admin',
        'hotel_id' => userHotel(userAccount('other'), 'far')->id,
    ])->assertOk()
        ->assertJsonPath('body.name', 'New')
        ->assertJsonPath('body.hotel_ids', []);

    $fresh = $user->fresh();

    expect($fresh->password)->toBe($hash)
        ->and($fresh->isEmployee())->toBeTrue()
        ->and($fresh->hotel_id)->toBe($hotel->id)
        ->and(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.updated')->count())->toBe(1);
});

it('refuses a taken email, and records nothing for an edit that changes nothing', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['name' => 'Same']);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}", ['email' => 'taken@example.com'])->assertStatus(422);
    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}", ['name' => 'Same'])->assertOk();

    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.updated')->count())->toBe(0);
});

// Access

it('refuses role=super_admin on an access change', function () {
    $user = User::factory()->create();

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'super_admin'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'The super admin role cannot be assigned through the API.');

    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});

it('replaces memberships, keeping the home hotel reachable', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $a = userHotel($account, 'a');
    $b = userHotel($account, 'b');
    $user = User::factory()->create(['hotel_id' => $home->id]);
    addMembership($user, $a);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['hotel_ids' => [$b->id]])
        ->assertOk()
        ->assertJsonPath('body.hotel_ids', [$b->id]);

    expect($user->fresh()->accessibleHotelIds())->toEqualCanonicalizing([$home->id, $b->id]);

    $row = DB::table('hotel_user')->where('user_id', $user->id)->sole();
    expect($row->role)->toBe('employee')->and($row->is_primary)->toBeFalse();
});

it('refuses a membership outside the user\'s account, or in a deleted hotel, changing nothing', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $kept = userHotel($account, 'kept');
    $deleted = userHotel($account, 'gone');
    $deleted->delete();
    $foreign = userHotel(userAccount('other'), 'far');
    $user = User::factory()->create(['hotel_id' => $home->id]);
    addMembership($user, $kept);

    foreach ([$foreign->id, $deleted->id] as $hotelId) {
        userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", [
            'role' => 'admin',
            'hotel_ids' => [$hotelId],
        ])->assertStatus(422);
    }

    $fresh = $user->fresh();

    expect($fresh->accessibleHotelIds())->toEqualCanonicalizing([$home->id, $kept->id])
        ->and($fresh->isEmployee())->toBeTrue()
        ->and(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.access_changed')->count())->toBe(0);
});

it('keeps a membership in a soft-deleted hotel through a change, so a restore returns access', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $paused = userHotel($account, 'paused');
    $other = userHotel($account, 'other');
    $user = User::factory()->create(['hotel_id' => $home->id]);
    addMembership($user, $paused);
    $paused->delete();

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['hotel_ids' => [$other->id]])
        ->assertOk()
        ->assertJsonPath('body.hotel_ids', [$other->id]);

    expect($user->fresh()->accessibleHotelIds())->toEqualCanonicalizing([$home->id, $other->id]);

    $paused->restore();

    expect($user->fresh()->accessibleHotelIds())->toEqualCanonicalizing([$home->id, $other->id, $paused->id]);
});

it('keeps membership roles in step with the user\'s role', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $a = userHotel($account, 'a');
    $user = User::factory()->create(['hotel_id' => $home->id]);
    addMembership($user, $a);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'admin'])->assertOk();

    expect(DB::table('hotel_user')->where('user_id', $user->id)->value('role'))->toBe('admin');
});

it('attaches an admin to their account when given an account-wide role', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $second = userHotel($account, 'second');
    $user = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $home->id]);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['group_role' => 'group_admin'])
        ->assertOk()
        ->assertJsonPath('body.hotel_group_id', $account->id)
        ->assertJsonPath('body.group_role', 'group_admin');

    expect($user->fresh()->accessibleHotelIds())->toEqualCanonicalizing([$home->id, $second->id]);
});

it('refuses an account-wide role for an employee, or a user with no account', function () {
    $employee = User::factory()->create(['hotel_id' => userHotel(userAccount())->id]);
    $loose = User::factory()->role(UserRole::ADMIN)->create();

    userAdmin($this)->putJson("/api/superadmin/users/{$employee->id}/access", ['group_role' => 'owner'])->assertStatus(422);
    userAdmin($this)->putJson("/api/superadmin/users/{$loose->id}/access", ['group_role' => 'owner'])->assertStatus(422);

    expect($employee->fresh()->group_role)->toBeNull()->and($loose->fresh()->group_role)->toBeNull();
});

it('removes the account-wide role when an admin becomes an employee', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $second = userHotel($account, 'second');
    $user = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $home->id, 'hotel_group_id' => $account->id, 'group_role' => 'owner']);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'employee'])->assertOk();

    $fresh = $user->fresh();

    expect($fresh->group_role)->toBeNull()
        ->and($fresh->accessibleHotelIds())->toBe([$home->id])
        ->and($fresh->accessibleHotelIds())->not->toContain($second->id);
});

it('applies the staff role rules', function () {
    $account = userAccount();
    $home = userHotel($account, 'home');
    $other = userHotel($account, 'other');
    $mine = StaffRole::create(['hotel_id' => $home->id, 'name' => 'Front Desk', 'permissions' => []]);
    $theirs = StaffRole::create(['hotel_id' => $other->id, 'name' => 'Other', 'permissions' => []]);
    $employee = User::factory()->create(['hotel_id' => $home->id]);
    $noHome = User::factory()->create();

    userAdmin($this)->putJson("/api/superadmin/users/{$employee->id}/access", ['staff_role_id' => $mine->id])
        ->assertOk()->assertJsonPath('body.staff_role_id', $mine->id);

    userAdmin($this)->putJson("/api/superadmin/users/{$employee->id}/access", ['staff_role_id' => $theirs->id])->assertStatus(403);
    userAdmin($this)->putJson("/api/superadmin/users/{$noHome->id}/access", ['staff_role_id' => $mine->id])->assertStatus(403);
    userAdmin($this)->putJson("/api/superadmin/users/{$employee->id}/access", ['role' => 'admin', 'staff_role_id' => $mine->id])->assertStatus(422);

    expect($employee->fresh()->staff_role_id)->toBe($mine->id);

    // Becoming an admin removes it.
    userAdmin($this)->putJson("/api/superadmin/users/{$employee->id}/access", ['role' => 'admin'])
        ->assertOk()->assertJsonPath('body.staff_role_id', null);
});

it('makes a promoted employee the owner of an ownerless home hotel, and frees the hotel on demotion', function () {
    $account = userAccount();
    $hotel = userHotel($account);
    $alsoOwned = userHotel($account, 'also');
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'admin'])->assertOk();
    expect($hotel->fresh()->owner_id)->toBe($user->id);

    $alsoOwned->update(['owner_id' => $user->id]);
    $alsoOwned->delete();

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'employee'])->assertOk();

    expect($hotel->fresh()->owner_id)->toBeNull()
        ->and(Hotel::withTrashed()->find($alsoOwned->id)->owner_id)->toBeNull();

    // The next admin created can own the hotel.
    $next = userAdmin($this)->postJson('/api/superadmin/users', newUserPayload($hotel))->assertCreated()->json('body.id');
    expect($hotel->fresh()->owner_id)->toBe($next);
});

it('does not replace an existing owner when an employee is promoted', function () {
    $hotel = userHotel(userAccount());
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);
    $hotel->update(['owner_id' => $owner->id]);
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'admin'])->assertOk();

    expect($hotel->fresh()->owner_id)->toBe($owner->id);
});

it('records one event per access change, and none when nothing changes', function () {
    $hotel = userHotel(userAccount());
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'employee'])->assertOk();
    expect(EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.access_changed')->count())->toBe(0);

    userAdmin($this)->putJson("/api/superadmin/users/{$user->id}/access", ['role' => 'admin'])->assertOk();

    $event = EventLog::withoutGlobalScopes()->where('event_type', 'platform.user.access_changed')->sole();
    expect($event->changes['role'])->toBe(['from' => 'employee', 'to' => 'admin'])
        ->and($event->hotel_id)->toBeNull();
});
