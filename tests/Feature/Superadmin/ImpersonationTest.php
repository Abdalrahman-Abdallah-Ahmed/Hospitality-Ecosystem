<?php

use App\Enums\ActorKind;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\ActivityCategory;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\StaffRole;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Support\Audit\EventLogger;
use App\Support\Impersonation\CurrentImpersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Sentry\Event as SentryEvent;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/*
| Spec 027: a super admin acting as a hotel user for up to 30 minutes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * Forget the last request's token, as a new request would. The test process
 * keeps that request, and User refuses to be written during a session.
 */
function imLeaveSession(): void
{
    app('auth')->forgetGuards();
}

function imSuperAdmin(): User
{
    imLeaveSession();

    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

/**
 * @return array{token: string, token_id: int, expires_at: string}
 */
function imStart($test, User $superAdmin, User $target): array
{
    app('auth')->forgetGuards();

    return $test->withHeaders(apiHeaders())
        ->actingAs($superAdmin, 'sanctum')
        ->postJson("/api/superadmin/users/{$target->id}/impersonate")
        ->assertOk()
        ->json('body');
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 */
function imRequest($test, string $token, string $method, string $uri, array $payload = [], array $headers = []): TestResponse
{
    app('auth')->forgetGuards();

    return $test->json($method, $uri, $payload, [...apiHeaders(), 'Authorization' => 'Bearer '.$token, ...$headers]);
}

/**
 * A request as this user with their own login token.
 *
 * @param  array<string, mixed>  $payload
 */
function imOwnRequest($test, User $user, string $method, string $uri, array $payload = []): TestResponse
{
    imLeaveSession();

    return imRequest($test, $user->createToken('api-token')->plainTextToken, $method, $uri, $payload);
}

/**
 * Super admin endpoints sent on a real token, so a request can't reuse the
 * impersonation token's cached user.
 *
 * @param  array<string, mixed>  $payload
 */
function imAsSuperAdmin($test, User $superAdmin, string $method, string $uri, array $payload = []): TestResponse
{
    return imOwnRequest($test, $superAdmin, $method, $uri, $payload);
}

function imEvents(string $type): int
{
    return EventLog::withoutGlobalScopes()->where('event_type', $type)->count();
}

function imEvent(string $type): EventLog
{
    return EventLog::withoutGlobalScopes()->where('event_type', $type)->latest('occurred_at')->orderByDesc('id')->firstOrFail();
}

/**
 * @param  list<Permission>  $permissions
 */
function imEmployee(Hotel $hotel, array $permissions, array $others = []): User
{
    $role = StaffRole::withoutGlobalScopes()->create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return spUser($hotel, $others, UserRole::EMPLOYEE, ['staff_role_id' => $role->id]);
}

function imGuest(Hotel $hotel, string $name = 'Existing'): Guest
{
    return Guest::withoutGlobalScopes()->create(['hotel_id' => $hotel->id, 'first_name' => $name, 'channel' => 'booking_com', 'external_id' => (string) Str::uuid()]);
}

/**
 * @return array<string, mixed> the tags on the current Sentry scope
 */
function imSentryTags(): array
{
    $tags = [];

    Sentry\configureScope(function (Scope $scope) use (&$tags) {
        $tags = $scope->applyToEvent(SentryEvent::createEvent())?->getTags() ?? [];
    });

    return $tags;
}

/*
| Story 1: start a session and see what the user sees.
*/

it('starts a session that expires in 30 minutes', function () {
    $this->freezeTime();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());

    $body = imStart($this, $superAdmin, $target);
    $row = PersonalAccessToken::find($body['token_id']);

    expect($body['token'])->toBeString()
        ->and($body['expires_at'])->toBe(now()->addMinutes(30)->toIso8601String())
        ->and($row->name)->toBe('impersonation')
        ->and($row->tokenable_id)->toBe($target->id)
        ->and($row->getAttribute('impersonator_id'))->toBe($superAdmin->id)
        ->and($row->expires_at->equalTo(now()->addMinutes(30)->startOfSecond()))->toBeTrue();
});

it('never stores the plain token in the audit trail', function () {
    $target = adminOfHotel(makeHotel());
    $body = imStart($this, imSuperAdmin(), $target);
    [, $secret] = explode('|', $body['token'], 2);

    $row = EventLog::withoutGlobalScopes()->where('event_type', 'platform.impersonation.started')->firstOrFail();

    expect(json_encode($row->getAttributes()))->not->toContain($secret);
});

it('applies the user\'s permissions', function () {
    $hotel = makeHotel();
    $employee = imEmployee($hotel, [Permission::GUESTS_VIEW]);
    $token = imStart($this, imSuperAdmin(), $employee)['token'];

    imRequest($this, $token, 'GET', '/api/guest')->assertOk();
    imRequest($this, $token, 'GET', '/api/room')->assertForbidden();
});

it('cannot read a record of a hotel the user cannot reach', function () {
    $guest = imGuest(makeHotel());
    $token = imStart($this, imSuperAdmin(), adminOfHotel(makeHotel()))['token'];

    imRequest($this, $token, 'GET', "/api/guest/{$guest->id}")->assertNotFound();
});

it('lets an owner session choose any hotel of the account, and only those', function () {
    [$account, $a, $b] = spAccount();
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $a->id, 'hotel_group_id' => $account->id, 'group_role' => 'owner']);
    $guest = imGuest($b);
    $other = makeHotel();
    $token = imStart($this, imSuperAdmin(), $owner)['token'];

    imRequest($this, $token, 'GET', "/api/guest/{$guest->id}", headers: ['X-Hotel-Id' => $b->id])->assertOk();
    imRequest($this, $token, 'GET', '/api/guest', headers: ['X-Hotel-Id' => $other->id])->assertForbidden();
});

it('works in an admin\'s default hotel when no header is sent', function () {
    [, $a, $b] = spAccount();
    $admin = spUser($a, [$b]);
    $inA = imGuest($a, 'InA');
    $inB = imGuest($b, 'InB');
    $token = imStart($this, imSuperAdmin(), $admin)['token'];

    imRequest($this, $token, 'GET', "/api/guest/{$inA->id}")->assertOk();
    imRequest($this, $token, 'GET', "/api/guest/{$inB->id}")->assertNotFound();
});

it('keeps an employee\'s whole scope', function () {
    [, $a, $b] = spAccount();
    $employee = imEmployee($a, [Permission::GUESTS_VIEW], [$b]);
    imGuest($a, 'InA');
    imGuest($b, 'InB');
    $token = imStart($this, imSuperAdmin(), $employee)['token'];

    $names = collect(imRequest($this, $token, 'GET', '/api/guest')->assertOk()->json('body.data'))->pluck('first_name');

    expect($names)->toContain('InA')->toContain('InB');
});

it('refuses a super admin target, including the caller', function () {
    $superAdmin = imSuperAdmin();

    foreach ([imSuperAdmin(), $superAdmin] as $target) {
        imAsSuperAdmin($this, $superAdmin, 'POST', "/api/superadmin/users/{$target->id}/impersonate")->assertStatus(422);
    }

    expect(PersonalAccessToken::where('name', 'impersonation')->count())->toBe(0)
        ->and(imEvents('platform.impersonation.started'))->toBe(0);
});

it('answers 404 for an unknown user', function () {
    imAsSuperAdmin($this, imSuperAdmin(), 'POST', '/api/superadmin/users/'.Str::uuid().'/impersonate')->assertNotFound();
});

it('refuses an impersonation token on every super admin route', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $other = User::factory()->create(['hotel_id' => $hotel->id]);
    $session = imStart($this, imSuperAdmin(), $target);

    imRequest($this, $session['token'], 'POST', "/api/superadmin/users/{$other->id}/impersonate")->assertForbidden();
    imRequest($this, $session['token'], 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertForbidden();
    imRequest($this, $session['token'], 'POST', "/api/superadmin/users/{$target->id}/end-impersonation")->assertForbidden();
    imRequest($this, $session['token'], 'GET', '/api/superadmin/users')->assertForbidden();

    expect(PersonalAccessToken::where('name', 'impersonation')->count())->toBe(1);
});

it('reports the session on GET /api/user', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $session = imStart($this, $superAdmin, $target);

    imRequest($this, $session['token'], 'GET', '/api/user')
        ->assertOk()
        ->assertJsonPath('body.id', $target->id)
        ->assertJsonPath('body.impersonation.impersonator.id', $superAdmin->id)
        ->assertJsonPath('body.impersonation.impersonator.name', $superAdmin->name)
        ->assertJsonPath('body.impersonation.expires_at', $session['expires_at'])
        ->assertJsonMissingPath('body.impersonation.impersonator.email');
});

it('has no impersonation key on the user\'s own GET /api/user', function () {
    $target = adminOfHotel(makeHotel());
    imStart($this, imSuperAdmin(), $target);

    imOwnRequest($this, $target, 'GET', '/api/user')->assertOk()->assertJsonMissingPath('body.impersonation');
});

it('has no impersonation key on a list of users', function () {
    $target = adminOfHotel(makeHotel());
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    $users = imRequest($this, $token, 'GET', '/api/users')->assertOk()->json('body.data');

    expect(collect($users)->pluck('id'))->toContain($target->id)
        ->and(collect($users)->filter(fn (array $user) => array_key_exists('impersonation', $user)))->toBeEmpty();
});

/*
| Story 2: sessions end on time and on request.
*/

it('works until 30 minutes and stops after, with no event', function () {
    $this->freezeTime();
    $token = imStart($this, imSuperAdmin(), adminOfHotel(makeHotel()))['token'];

    $this->travel(29)->minutes();
    $this->travel(59)->seconds();
    imRequest($this, $token, 'GET', '/api/user')->assertOk();

    $this->travel(2)->seconds();
    imRequest($this, $token, 'GET', '/api/user')->assertUnauthorized();
    imRequest($this, $token, 'GET', '/api/guest')->assertUnauthorized();

    expect(imEvents('platform.impersonation.ended'))->toBe(0)
        ->and(imEvent('platform.impersonation.started')->context['expires_at'])->not->toBeNull();
});

it('lets any super admin end a session by id', function () {
    $target = adminOfHotel(makeHotel());
    $other = imSuperAdmin();
    $session = imStart($this, imSuperAdmin(), $target);

    imAsSuperAdmin($this, $other, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertOk();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    $event = imEvent('platform.impersonation.ended');

    expect(imEvents('platform.impersonation.ended'))->toBe(1)
        ->and($event->actor_id)->toBe($other->id)
        ->and($event->subject_id)->toBe($target->id)
        ->and($event->hotel_id)->toBeNull()
        ->and($event->context['impersonated_user_id'])->toBe($target->id)
        ->and($event->context['token_id'])->toBe($session['token_id']);
});

it('answers 404 and leaves the token for anything that is not an impersonation token', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $login = $target->createToken('api-token')->accessToken;
    $pairing = PersonalAccessToken::findToken(pairingCodeFor($target));

    foreach ([$login->id, $pairing->id, 999999] as $id) {
        imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $id])->assertNotFound();
    }

    expect(PersonalAccessToken::find($login->id))->not->toBeNull()
        ->and(PersonalAccessToken::find($pairing->id))->not->toBeNull()
        ->and(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('validates token_id', function (array $payload) {
    imAsSuperAdmin($this, imSuperAdmin(), 'POST', '/api/superadmin/impersonation/end', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('token_id');
})->with([
    'missing' => [[]],
    'not an integer' => [['token_id' => 'abc']],
    'a boolean, which would read as id 1' => [['token_id' => true]],
]);

it('answers 404 when the session has already ended, with no second event', function () {
    $superAdmin = imSuperAdmin();
    $session = imStart($this, $superAdmin, adminOfHotel(makeHotel()));

    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertOk();
    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertNotFound();

    expect(imEvents('platform.impersonation.ended'))->toBe(1);
});

it('answers 404 for an expired session that is not pruned yet, with no event', function () {
    $superAdmin = imSuperAdmin();
    $session = imStart($this, $superAdmin, adminOfHotel(makeHotel()));

    $this->travel(31)->minutes();

    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertNotFound();

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('answers 404 with no server error when the user has been deleted', function () {
    $superAdmin = imSuperAdmin();
    $target = User::factory()->create(['hotel_id' => makeHotel()->id]);
    $session = imStart($this, $superAdmin, $target);

    $target->delete();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();
    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/impersonation/end', ['token_id' => $session['token_id']])->assertNotFound();

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('ends the session on logout, with the same event', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $session = imStart($this, $superAdmin, $target);

    imRequest($this, $session['token'], 'POST', '/api/logout')->assertOk();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    $event = imEvent('platform.impersonation.ended');

    expect(imEvents('platform.impersonation.ended'))->toBe(1)
        ->and($event->hotel_id)->toBeNull()
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->subject_id)->toBe($target->id)
        ->and($event->context['impersonated_user_id'])->toBe($target->id)
        ->and($event->context['token_id'])->toBe($session['token_id']);
});

it('records nothing when a normal token logs out', function () {
    imOwnRequest($this, adminOfHotel(makeHotel()), 'POST', '/api/logout')->assertOk();

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('answers 401 to logout with an expired token, with no event', function () {
    $token = imStart($this, imSuperAdmin(), adminOfHotel(makeHotel()))['token'];

    $this->travel(31)->minutes();

    imRequest($this, $token, 'POST', '/api/logout')->assertUnauthorized();

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('gives a second session its own 30 minutes, and leaves the first expiry alone', function () {
    $this->freezeTime();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $first = imStart($this, $superAdmin, $target);

    $this->travel(10)->minutes();
    $second = imStart($this, $superAdmin, $target);

    $this->travel(21)->minutes();

    imRequest($this, $first['token'], 'GET', '/api/user')->assertUnauthorized();
    imRequest($this, $second['token'], 'GET', '/api/user')->assertOk();

    expect($first['token_id'])->not->toBe($second['token_id']);
});

it('prunes tokens expired over an hour ago and keeps running sessions', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $old = imStart($this, $superAdmin, $target);
    $oldCode = PersonalAccessToken::findToken(pairingCodeFor($target, now()->addMinutes(15)));

    $this->travel(2)->hours();
    $running = imStart($this, $superAdmin, $target);

    $this->artisan('sanctum:prune-expired', ['--hours' => 1])->assertSuccessful();

    expect(PersonalAccessToken::find($old['token_id']))->toBeNull()
        ->and(PersonalAccessToken::find($oldCode->id))->toBeNull()
        ->and(PersonalAccessToken::find($running['token_id']))->not->toBeNull();
});

it('ends all of a user\'s sessions and nothing else', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $caller = imSuperAdmin();
    $first = imStart($this, imSuperAdmin(), $target);
    $second = imStart($this, imSuperAdmin(), $target);
    $login = $target->createToken('api-token');
    WhatsAppDevice::create(['user_id' => $target->id, 'phone_number' => '201000000021', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-21', 'status' => 'active']);

    imAsSuperAdmin($this, $caller, 'POST', "/api/superadmin/users/{$target->id}/end-impersonation")
        ->assertOk()
        ->assertJsonPath('body.ended', 2);

    imRequest($this, $first['token'], 'GET', '/api/user')->assertUnauthorized();
    imRequest($this, $second['token'], 'GET', '/api/user')->assertUnauthorized();
    imRequest($this, $login->plainTextToken, 'GET', '/api/user')->assertOk();

    $ended = EventLog::withoutGlobalScopes()->where('event_type', 'platform.impersonation.ended')->get();

    expect($ended)->toHaveCount(2)
        ->and($ended->pluck('actor_id')->unique()->all())->toBe([$caller->id])
        ->and($ended->pluck('context.token_id')->sort()->values()->all())->toBe(collect([$first['token_id'], $second['token_id']])->sort()->values()->all())
        ->and(WhatsAppDevice::where('user_id', $target->id)->exists())->toBeTrue();
});

it('ends nothing and records nothing when the user has no running session', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());

    imAsSuperAdmin($this, $superAdmin, 'POST', "/api/superadmin/users/{$target->id}/end-impersonation")
        ->assertOk()->assertJsonPath('body.ended', 0);

    imStart($this, $superAdmin, $target);
    $this->travel(31)->minutes();

    imAsSuperAdmin($this, $superAdmin, 'POST', "/api/superadmin/users/{$target->id}/end-impersonation")
        ->assertOk()->assertJsonPath('body.ended', 0);

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('answers 404 to end-impersonation for an unknown user or a super admin', function () {
    $superAdmin = imSuperAdmin();

    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/users/'.Str::uuid().'/end-impersonation')->assertNotFound();
    imAsSuperAdmin($this, $superAdmin, 'POST', '/api/superadmin/users/'.imSuperAdmin()->id.'/end-impersonation')->assertNotFound();
});

it('still unpairs the device on revoke-tokens', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $session = imStart($this, $superAdmin, $target);
    WhatsAppDevice::create(['user_id' => $target->id, 'phone_number' => '201000000022', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-22', 'status' => 'active']);

    imAsSuperAdmin($this, $superAdmin, 'POST', "/api/superadmin/users/{$target->id}/revoke-tokens")->assertOk();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    expect(WhatsAppDevice::where('user_id', $target->id)->exists())->toBeFalse()
        ->and($target->tokens()->count())->toBe(0);
});

/*
| Story 3: the audit trail names the real super admin.
*/

it('records a write with the super admin as actor and the user in context', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $token = imStart($this, $superAdmin, $target)['token'];

    $id = imRequest($this, $token, 'POST', '/api/guest', ['hotel_id' => $hotel->id, 'first_name' => 'Attributed', 'channel' => 'booking_com', 'external_id' => 'im-1'])
        ->assertCreated()->json('body.id');

    $event = EventLog::withoutGlobalScopes()->where('event_type', 'guest.created')->where('subject_id', $id)->firstOrFail();

    expect($event->actor_id)->toBe($superAdmin->id)
        ->and($event->actor_kind)->toBe(ActorKind::USER)
        ->and($event->context['impersonated_user_id'])->toBe($target->id)
        ->and($event->hotel_id)->toBe($hotel->id);
});

it('records the user\'s own write as theirs', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    imStart($this, imSuperAdmin(), $target);

    $id = imOwnRequest($this, $target, 'POST', '/api/guest', ['hotel_id' => $hotel->id, 'first_name' => 'Own', 'channel' => 'booking_com', 'external_id' => 'im-2'])
        ->assertCreated()->json('body.id');

    $event = EventLog::withoutGlobalScopes()->where('event_type', 'guest.created')->where('subject_id', $id)->firstOrFail();

    expect($event->actor_id)->toBe($target->id)
        ->and($event->context ?? [])->not->toHaveKey('impersonated_user_id');
});

it('resolves the same actor for a platform event in a session\'s request', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $token = imStart($this, $superAdmin, $target)['token'];

    app('auth')->forgetGuards();
    Auth::setUser($target->withAccessToken(PersonalAccessToken::findToken($token)));

    EventLogger::recordPlatform('platform.test.happened', $target);

    $event = imEvent('platform.test.happened');

    expect($event->actor_id)->toBe($superAdmin->id)
        ->and($event->hotel_id)->toBeNull()
        ->and($event->context['impersonated_user_id'])->toBe($target->id);
});

it('keeps an AI write as ai_agent and names the super admin', function () {
    Http::fake();
    Queue::fake();
    $s = aatSeed();
    $superAdmin = imSuperAdmin();
    $token = imStart($this, $superAdmin, $s['admin'])['token'];

    app('auth')->forgetGuards();
    Auth::setUser($s['admin']->withAccessToken(PersonalAccessToken::findToken($token)));

    aatCall(aatTool($s['admin'], 'CreateGuestTool', 'conv-1'), ['phone_number' => '+44 7700 900124', 'first_name' => 'ThroughAi'], 'call-1');

    $guest = Guest::withoutGlobalScope('hotel')->where('first_name', 'ThroughAi')->firstOrFail();
    $event = EventLog::withoutGlobalScopes()->where('event_type', 'guest.created')->where('subject_id', $guest->id)->firstOrFail();

    expect($event->actor_kind)->toBe(ActorKind::AI_AGENT)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->context['impersonated_user_id'])->toBe($s['admin']->id)
        ->and($event->context['ai']['tool'])->toBe('CreateGuestTool');
});

it('records the start event', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $session = imStart($this, $superAdmin, $target);

    $event = imEvent('platform.impersonation.started');

    expect($event->actor_id)->toBe($superAdmin->id)
        ->and($event->actor_kind)->toBe(ActorKind::USER)
        ->and($event->subject_id)->toBe($target->id)
        ->and($event->hotel_id)->toBeNull()
        ->and($event->context['impersonated_user_id'])->toBe($target->id)
        ->and($event->context['token_id'])->toBe($session['token_id'])
        ->and($event->context['expires_at'])->toBe($session['expires_at']);
});

it('keeps session events off every hotel\'s event rows', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $session = imStart($this, imSuperAdmin(), $target);
    imRequest($this, $session['token'], 'POST', '/api/logout')->assertOk();

    expect(TenantContext::runForHotel($hotel->id, fn () => EventLog::query()->where('event_type', 'like', 'platform.impersonation.%')->count()))->toBe(0);

    imOwnRequest($this, $target, 'GET', "/api/history/guest/{$target->id}")->assertNotFound();

    $this->assertTrue(
        EventLog::withoutGlobalScopes()->whereIn('event_type', ['platform.impersonation.started', 'platform.impersonation.ended'])->whereNotNull('hotel_id')->doesntExist()
    );
});

it('records a job\'s later write as the hotel user', function () {
    $target = adminOfHotel(makeHotel());
    imStart($this, imSuperAdmin(), $target);

    app('auth')->forgetGuards();

    EventLogger::actingAs($target, fn () => EventLogger::recordPlatform('platform.test.job', $target));

    $event = imEvent('platform.test.job');

    expect($event->actor_id)->toBe($target->id)
        ->and($event->context ?? [])->not->toHaveKey('impersonated_user_id');
});

dataset('unaudited writes', [
    'hotel settings' => ['PUT', fn (Hotel $h) => "/api/hotel/{$h->id}", ['name' => 'Renamed'], 'hotel.updated', ['name']],
    'team create' => ['POST', fn () => '/api/team', ['name' => 'Night team'], 'team.created', ['name']],
    'team update' => ['PUT', fn (Hotel $h) => '/api/team/'.Team::create(['hotel_id' => $h->id, 'name' => 'Old'])->id, ['name' => 'New'], 'team.updated', ['name']],
    'team delete' => ['DELETE', fn (Hotel $h) => '/api/team/'.Team::create(['hotel_id' => $h->id, 'name' => 'Gone'])->id, [], 'team.deleted', []],
    'task category create' => ['POST', fn () => '/api/task-category', ['name' => 'Linen'], 'task_category.created', ['name']],
    'task category update' => ['PUT', fn (Hotel $h) => '/api/task-category/'.TaskCategory::create(['hotel_id' => $h->id, 'name' => 'Old'])->id, ['name' => 'New'], 'task_category.updated', ['name']],
    'task category delete' => ['DELETE', fn (Hotel $h) => '/api/task-category/'.TaskCategory::create(['hotel_id' => $h->id, 'name' => 'Gone'])->id, [], 'task_category.deleted', []],
    'activity category create' => ['POST', fn () => '/api/activity-category', ['name' => 'Tours', 'slug' => 'tours'], 'activity_category.created', ['name']],
    'activity category update' => ['PUT', fn (Hotel $h) => '/api/activity-category/'.ActivityCategory::create(['hotel_id' => $h->id, 'name' => 'Old', 'slug' => 'old'])->id, ['name' => 'New'], 'activity_category.updated', ['name']],
    'activity category delete' => ['DELETE', fn (Hotel $h) => '/api/activity-category/'.ActivityCategory::create(['hotel_id' => $h->id, 'name' => 'Gone', 'slug' => 'gone'])->id, [], 'activity_category.deleted', []],
    'hotel policy create' => ['POST', fn () => '/api/hotel-policy', ['title' => 'Pets', 'content' => 'Secret policy text', 'category' => 'hospitality_best_practices'], 'hotel_policy.created', ['title']],
    'hotel policy update' => ['PUT', fn (Hotel $h) => '/api/hotel-policy/'.HotelPolicy::create(['hotel_id' => $h->id, 'title' => 'Old', 'content' => 'Old text', 'category' => 'hospitality_best_practices'])->id, ['title' => 'New'], 'hotel_policy.updated', ['title']],
    'hotel policy delete' => ['DELETE', fn (Hotel $h) => '/api/hotel-policy/'.HotelPolicy::create(['hotel_id' => $h->id, 'title' => 'Gone', 'content' => 'Old text', 'category' => 'hospitality_best_practices'])->id, [], 'hotel_policy.deleted', []],
]);

it('records one event for a session write to an unaudited record type, by field name only', function (string $method, Closure $uri, array $payload, string $type, array $fields) {
    Queue::fake();
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $path = $uri($hotel);
    $token = imStart($this, $superAdmin, $target)['token'];

    imRequest($this, $token, $method, $path, $method === 'POST' ? ['hotel_id' => $hotel->id, ...$payload] : $payload)->assertSuccessful();

    $events = EventLog::withoutGlobalScopes()->where('event_type', $type)->get();
    $event = $events->first();

    expect($events)->toHaveCount(1)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->hotel_id)->toBe($hotel->id)
        ->and($event->changes)->toBeNull()
        ->and($event->context['impersonated_user_id'])->toBe($target->id);

    foreach ($fields as $field) {
        expect($event->context['changed_fields'])->toContain($field);
    }

    foreach ($payload as $value) {
        expect(json_encode($event->getAttributes()))->not->toContain((string) $value);
    }
})->with('unaudited writes');

it('records nothing new for the same write with the user\'s own token', function (string $method, Closure $uri, array $payload, string $type) {
    Queue::fake();
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $path = $uri($hotel);

    imOwnRequest($this, $target, $method, $path, $method === 'POST' ? ['hotel_id' => $hotel->id, ...$payload] : $payload)->assertSuccessful();

    expect(imEvents($type))->toBe(0);
})->with('unaudited writes');

it('keeps the guest memories event beside the settings event', function () {
    $hotel = makeHotel();
    $token = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];

    imRequest($this, $token, 'PUT', "/api/hotel/{$hotel->id}", ['guest_memories_enabled' => ! $hotel->fresh()->guest_memories_enabled])->assertOk();

    expect(imEvents('hotel.updated'))->toBe(1)
        ->and(imEvents('hotel.guest_memories_toggled'))->toBe(1);
});

it('records nothing when a session write changes nothing or is refused', function () {
    $hotel = makeHotel();
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Same']);
    $other = Team::create(['hotel_id' => makeHotel()->id, 'name' => 'Theirs']);
    $employee = imEmployee($hotel, [Permission::TEAMS_VIEW]);
    $adminToken = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];
    $employeeToken = imStart($this, imSuperAdmin(), $employee)['token'];

    imRequest($this, $adminToken, 'PUT', "/api/team/{$team->id}", ['name' => 'Same'])->assertOk();
    imRequest($this, $adminToken, 'PUT', "/api/team/{$other->id}", ['name' => 'Taken'])->assertNotFound();
    imRequest($this, $employeeToken, 'PUT', "/api/team/{$team->id}", ['name' => 'Refused'])->assertForbidden();

    expect(imEvents('team.updated'))->toBe(0)
        ->and($team->fresh()->name)->toBe('Same');
});

/**
 * A guest with one history row, written directly so it can carry an IP, which
 * is only set outside the console.
 */
function imHistoryRow(Hotel $hotel, User $actor, array $context = []): Guest
{
    $guest = imGuest($hotel);

    EventLog::withoutGlobalScopes()->where('subject_id', $guest->id)->delete();
    EventLog::withoutGlobalScopes()->create([
        'hotel_id' => $hotel->id,
        'event_type' => 'guest.updated',
        'subject_type' => $guest->getMorphClass(),
        'subject_id' => $guest->id,
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => $actor->id,
        'actor_kind' => ActorKind::USER->value,
        'context' => ['ip' => '203.0.113.9', ...$context],
        'evidence_level' => 'L1',
        'occurred_at' => now(),
    ]);

    return $guest;
}

it('shows hotel readers only a super admin\'s id and name, with no IP', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $reader = adminOfHotel($hotel);
    $guest = imHistoryRow($hotel, $superAdmin, ['impersonated_user_id' => $reader->id]);
    $token = imStart($this, $superAdmin, $reader)['token'];

    $responses = [
        imOwnRequest($this, $reader, 'GET', "/api/history/guest/{$guest->id}"),
        imRequest($this, $token, 'GET', "/api/history/guest/{$guest->id}"),
    ];

    foreach ($responses as $response) {
        $row = $response->assertOk()->json('body.data.0');

        expect($row['actor'])->toBe(['id' => $superAdmin->id, 'name' => $superAdmin->name])
            ->and($row['context'])->not->toHaveKey('ip')
            ->and($row['context']['impersonated_user_id'])->toBe($reader->id);
    }

    $stored = EventLog::withoutGlobalScopes()->where('subject_id', $guest->id)->firstOrFail();

    expect($stored->context['ip'])->toBe('203.0.113.9');
});

it('shows a super admin the full row', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $guest = imHistoryRow($hotel, $superAdmin);

    $row = imAsSuperAdmin($this, imSuperAdmin(), 'GET', "/api/history/guest/{$guest->id}")->assertOk()->json('body.data.0');

    expect($row['actor']['email'])->toBe($superAdmin->email)
        ->and($row['context']['ip'])->toBe('203.0.113.9');
});

it('leaves a hotel user\'s row as it is', function () {
    $hotel = makeHotel();
    $reader = adminOfHotel($hotel);
    $guest = imHistoryRow($hotel, $reader);

    $row = imOwnRequest($this, $reader, 'GET', "/api/history/guest/{$guest->id}")->assertOk()->json('body.data.0');

    expect($row['actor']['email'])->toBe($reader->email)
        ->and($row['context']['ip'])->toBe('203.0.113.9');
});

it('hides the IP when the actor no longer exists', function () {
    $hotel = makeHotel();
    $gone = imSuperAdmin();
    $guest = imHistoryRow($hotel, $gone);
    $gone->delete();

    $row = imOwnRequest($this, adminOfHotel($hotel), 'GET', "/api/history/guest/{$guest->id}")->assertOk()->json('body.data.0');

    expect($row['actor'])->toBeNull()
        ->and($row['context'])->not->toHaveKey('ip');
});

it('tags Sentry on a session\'s request, and clears it on the next', function () {
    config(['sentry.dsn' => 'https://public@example.ingest.sentry.io/1']);
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $token = imStart($this, $superAdmin, $target)['token'];

    $hub = SentrySdk::getCurrentHub();
    $hub->pushScope();

    try {
        imRequest($this, $token, 'GET', '/api/user')->assertOk();

        expect(imSentryTags()['impersonating'] ?? null)->toBe('true')
            ->and(imSentryTags()['impersonator_id'] ?? null)->toBe($superAdmin->id);

        imOwnRequest($this, $target, 'GET', '/api/user')->assertOk();

        expect(imSentryTags())->not->toHaveKey('impersonating')
            ->and(imSentryTags())->not->toHaveKey('impersonator_id');
    } finally {
        $hub->popScope();
    }
});

it('tags nothing and fails nothing with no DSN', function () {
    config(['sentry.dsn' => null]);
    $token = imStart($this, imSuperAdmin(), adminOfHotel(makeHotel()))['token'];

    $hub = SentrySdk::getCurrentHub();
    $hub->pushScope();

    try {
        imRequest($this, $token, 'GET', '/api/user')->assertOk();

        expect(imSentryTags())->not->toHaveKey('impersonating');
    } finally {
        $hub->popScope();
    }
});

/*
| Story 4: a session cannot gain lasting access.
*/

dataset('refused writes', [
    'connect' => ['POST', fn () => '/api/connect'],
    'create user' => ['POST', fn () => '/api/users'],
    'put user' => ['PUT', fn (Hotel $h, User $u) => "/api/users/{$u->id}"],
    'patch user' => ['PATCH', fn (Hotel $h, User $u) => "/api/users/{$u->id}"],
    'delete user' => ['DELETE', fn (Hotel $h, User $u) => "/api/users/{$u->id}"],
    'create staff role' => ['POST', fn () => '/api/staff-roles'],
    'put staff role' => ['PUT', fn (Hotel $h, User $u, StaffRole $r) => "/api/staff-roles/{$r->id}"],
    'patch staff role' => ['PATCH', fn (Hotel $h, User $u, StaffRole $r) => "/api/staff-roles/{$r->id}"],
    'delete staff role' => ['DELETE', fn (Hotel $h, User $u, StaffRole $r) => "/api/staff-roles/{$r->id}"],
    'add team member' => ['POST', fn (Hotel $h, User $u, StaffRole $r, Team $t) => "/api/team/{$t->id}/members"],
    'ai advisor chat' => ['POST', fn () => '/api/ai-advisor/chat'],
    'unpair device' => ['DELETE', fn () => '/api/whatsapp-device'],
]);

it('refuses every write that could gain lasting access', function (string $method, Closure $uri) {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $staff = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $role = StaffRole::withoutGlobalScopes()->create(['hotel_id' => $hotel->id, 'name' => 'Desk', 'permissions' => []]);
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Team']);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    $tokensBefore = PersonalAccessToken::count();
    $usersBefore = User::count();

    imRequest($this, $token, $method, $uri($hotel, $staff, $role, $team), [
        'name' => 'Changed', 'email' => 'changed@example.com', 'password' => 'A-new-Password1!', 'password_confirmation' => 'A-new-Password1!',
        'permissions' => [], 'user_id' => $staff->id,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is not available while impersonating.');

    expect(PersonalAccessToken::count())->toBe($tokensBefore)
        ->and(User::count())->toBe($usersBefore)
        ->and($staff->fresh()->name)->toBe($staff->name)
        ->and($staff->fresh()->team_id)->toBeNull()
        ->and($role->fresh()->name)->toBe('Desk');
})->with('refused writes');

it('cannot set the admin\'s own password', function () {
    $target = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => makeHotel()->id, 'email' => 'admin-im@example.com', 'password' => 'Original-Password1!']);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    imRequest($this, $token, 'PUT', "/api/users/{$target->id}", ['password' => 'A-new-Password1!'])->assertForbidden();

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    $this->withHeaders(apiHeaders())->postJson('/api/login', ['email' => 'admin-im@example.com', 'password' => 'Original-Password1!'])->assertOk();

    expect(Hash::check('Original-Password1!', $target->fresh()->password))->toBeTrue();
});

it('cannot set a staff member\'s password or email through an owner', function () {
    [$account, $a] = spAccount();
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $a->id, 'hotel_group_id' => $account->id, 'group_role' => 'owner']);
    $staff = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $a->id, 'email' => 'staff-im@example.com', 'password' => 'Original-Password1!']);
    $token = imStart($this, imSuperAdmin(), $owner)['token'];

    imRequest($this, $token, 'PUT', "/api/users/{$staff->id}", ['password' => 'A-new-Password1!', 'email' => 'taken@example.com'])->assertForbidden();

    expect($staff->fresh()->email)->toBe('staff-im@example.com')
        ->and(Hash::check('Original-Password1!', $staff->fresh()->password))->toBeTrue();
});

it('refuses before looking the record up', function () {
    $hotel = makeHotel();
    $otherHotel = makeHotel();
    $token = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];
    $otherRole = StaffRole::withoutGlobalScopes()->create(['hotel_id' => $otherHotel->id, 'name' => 'Theirs', 'permissions' => []]);
    $otherTeam = Team::create(['hotel_id' => $otherHotel->id, 'name' => 'Theirs']);

    $uris = [
        ['PUT', '/api/users/'.Str::uuid()],
        ['PUT', '/api/users/'.adminOfHotel($otherHotel)->id],
        ['DELETE', '/api/staff-roles/'.Str::uuid()],
        ['PUT', "/api/staff-roles/{$otherRole->id}"],
        ['POST', '/api/team/'.Str::uuid().'/members'],
        ['POST', "/api/team/{$otherTeam->id}/members"],
    ];

    foreach ($uris as [$method, $uri]) {
        imRequest($this, $token, $method, $uri)
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is not available while impersonating.');
    }
});

it('keeps reads of those routes open', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $role = StaffRole::withoutGlobalScopes()->create(['hotel_id' => $hotel->id, 'name' => 'Desk', 'permissions' => []]);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    imRequest($this, $token, 'GET', '/api/users')->assertOk();
    imRequest($this, $token, 'GET', "/api/users/{$target->id}")->assertOk();
    imRequest($this, $token, 'GET', '/api/staff-roles')->assertOk();
    imRequest($this, $token, 'GET', "/api/staff-roles/{$role->id}")->assertOk();
});

it('leaves the admin\'s own token and a super admin\'s own token as they were', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    imStart($this, imSuperAdmin(), $target);

    imOwnRequest($this, $target, 'POST', '/api/connect')->assertOk();
    imOwnRequest($this, $target, 'POST', '/api/staff-roles', ['hotel_id' => $hotel->id, 'name' => 'Own', 'permissions' => []])->assertCreated();
    imOwnRequest($this, $target, 'PUT', "/api/users/{$target->id}", ['name' => 'Renamed'])->assertOk();

    imAsSuperAdmin($this, imSuperAdmin(), 'PUT', "/api/users/{$target->id}", ['name' => 'Renamed again'])->assertOk();

    expect($target->fresh()->name)->toBe('Renamed again');
});

/*
| Story 5: support a suspended hotel without changing it.
*/

it('reads a suspended only hotel, and refuses every other method', function () {
    $hotel = makeHotel();
    $guest = imGuest($hotel);
    $token = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];
    spSuspend($hotel);

    imRequest($this, $token, 'GET', "/api/guest/{$guest->id}")->assertOk();
    imRequest($this, $token, 'HEAD', "/api/guest/{$guest->id}")->assertOk();
    imRequest($this, $token, 'GET', "/api/hotel/{$hotel->id}")->assertOk();

    foreach ([['POST', '/api/guest'], ['PUT', "/api/guest/{$guest->id}"], ['PATCH', "/api/guest/{$guest->id}"], ['DELETE', "/api/guest/{$guest->id}"]] as [$method, $uri]) {
        imRequest($this, $token, $method, $uri, ['first_name' => 'Changed'])->assertForbidden()->assertJsonPath('body.scope', 'hotel');
    }

    expect($guest->fresh()->first_name)->toBe('Existing');
});

it('reads a suspended account, and refuses a write with scope account', function () {
    [$account, $a] = spAccount();
    $guest = imGuest($a);
    $token = imStart($this, imSuperAdmin(), spUser($a))['token'];
    spSuspend($account);

    imRequest($this, $token, 'GET', "/api/guest/{$guest->id}")->assertOk();
    imRequest($this, $token, 'POST', '/api/guest', ['first_name' => 'No'])->assertForbidden()->assertJsonPath('body.scope', 'account');
});

it('ends the session on logout in a suspended hotel', function () {
    $hotel = makeHotel();
    $token = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];
    spSuspend($hotel);

    imRequest($this, $token, 'POST', '/api/logout')->assertOk();

    expect(imEvents('platform.impersonation.ended'))->toBe(1);
});

dataset('target with a suspended default hotel', [
    'admin' => [fn (Hotel $a, Hotel $b) => spUser($a, [$b])],
    'employee' => [fn (Hotel $a, Hotel $b) => imEmployee($a, [Permission::GUESTS_VIEW, Permission::GUESTS_CREATE, Permission::GUESTS_UPDATE], [$b])],
]);

it('reads the suspended default hotel and writes only to the active one', function (Closure $makeTarget) {
    [, $a, $b] = spAccount();
    $target = $makeTarget($a, $b);
    $inA = imGuest($a, 'InA');
    $token = imStart($this, imSuperAdmin(), $target)['token'];
    spSuspend($a);

    // A read with no header keeps the whole scope, so A's record is there.
    imRequest($this, $token, 'GET', "/api/guest/{$inA->id}")->assertOk();

    // A write with no header works in B, as for a normal user.
    $created = imRequest($this, $token, 'POST', '/api/guest', ['hotel_id' => $a->id, 'first_name' => 'NoHeader', 'channel' => 'booking_com', 'external_id' => 'im-b1'])
        ->assertCreated()->json('body.id');

    expect(Guest::withoutGlobalScopes()->find($created)->hotel_id)->toBe($b->id)
        ->and(Guest::withoutGlobalScopes()->where('hotel_id', $a->id)->count())->toBe(1);

    imRequest($this, $token, 'GET', "/api/guest/{$inA->id}", headers: ['X-Hotel-Id' => $a->id])->assertOk();
    imRequest($this, $token, 'POST', '/api/guest', ['first_name' => 'NamedA'], ['X-Hotel-Id' => $a->id])
        ->assertForbidden()->assertJsonPath('body.scope', 'hotel');

    imRequest($this, $token, 'POST', '/api/guest', ['hotel_id' => $b->id, 'first_name' => 'NamedB', 'channel' => 'booking_com', 'external_id' => 'im-b2'], ['X-Hotel-Id' => $b->id])->assertCreated();

    imRequest($this, $token, 'PUT', "/api/guest/{$inA->id}", ['first_name' => 'Changed'], ['X-Hotel-Id' => $b->id])->assertNotFound();

    expect($inA->fresh()->first_name)->toBe('InA');
})->with('target with a suspended default hotel');

it('refuses a write to the suspended hotel\'s own record', function () {
    [, $a, $b] = spAccount();
    $token = imStart($this, imSuperAdmin(), spUser($a, [$b]))['token'];
    spSuspend($a);
    $name = $a->name;

    imRequest($this, $token, 'PUT', "/api/hotel/{$a->id}", ['name' => 'Changed'], ['X-Hotel-Id' => $b->id])->assertForbidden();

    expect($a->fresh()->name)->toBe($name);
});

/*
| The spec's edge cases.
*/

it('ends the session with no event when the super admin who started it is deleted', function () {
    $superAdmin = imSuperAdmin();
    $session = imStart($this, $superAdmin, adminOfHotel(makeHotel()));

    $superAdmin->delete();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    expect(PersonalAccessToken::find($session['token_id']))->toBeNull()
        ->and(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('ends the session with no end event when the user\'s tokens are revoked', function () {
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $session = imStart($this, $superAdmin, $target);

    imAsSuperAdmin($this, $superAdmin, 'POST', "/api/superadmin/users/{$target->id}/revoke-tokens")->assertOk();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    expect(imEvents('platform.impersonation.ended'))->toBe(0)
        ->and(imEvents('platform.user.access_revoked'))->toBe(1);
});

it('ends the session with no end event when the user resets their password', function () {
    $target = adminOfHotel(makeHotel());
    $session = imStart($this, imSuperAdmin(), $target);
    $resetToken = Password::broker()->createToken($target);

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    $this->withHeaders(apiHeaders())->postJson('/api/reset-password', [
        'token' => $resetToken,
        'email' => $target->email,
        'password' => 'A-new-Password1!',
        'password_confirmation' => 'A-new-Password1!',
    ])->assertOk();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertUnauthorized();

    expect(imEvents('platform.impersonation.ended'))->toBe(0);
});

it('cannot redeem an impersonation token as a WhatsApp pairing code', function () {
    $target = adminOfHotel(makeHotel());
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    $this->withHeaders(apiHeaders())->postJson('/api/pair', [
        'token' => $token,
        'phone_number' => '201000000031',
        'wa_user_id' => 'wa-31',
    ])->assertJsonPath('message', 'Invalid token.');

    expect(WhatsAppDevice::where('user_id', $target->id)->exists())->toBeFalse();
});

it('gives a target with no hotels the same answers as their own token', function () {
    $target = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => null]);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    foreach ([['GET', '/api/guest'], ['GET', '/api/hotel'], ['POST', '/api/team', ['name' => 'Nope']]] as $call) {
        [$method, $uri] = $call;
        $payload = $call[2] ?? [];

        $session = imRequest($this, $token, $method, $uri, $payload);
        $own = imOwnRequest($this, $target, $method, $uri, $payload);

        expect($session->getStatusCode())->toBe($own->getStatusCode())
            ->and($session->json('body'))->toEqual($own->json('body'));
    }
});

it('runs sessions from two super admins side by side', function () {
    $first = imSuperAdmin();
    $second = imSuperAdmin();
    $target = adminOfHotel(makeHotel());
    $sessionOne = imStart($this, $first, $target);
    $sessionTwo = imStart($this, $second, $target);

    imRequest($this, $sessionOne['token'], 'GET', '/api/user')
        ->assertJsonPath('body.impersonation.impersonator.id', $first->id);
    imRequest($this, $sessionTwo['token'], 'GET', '/api/user')
        ->assertJsonPath('body.impersonation.impersonator.id', $second->id);

    imRequest($this, $sessionOne['token'], 'POST', '/api/logout')->assertOk();

    imRequest($this, $sessionOne['token'], 'GET', '/api/user')->assertUnauthorized();
    imRequest($this, $sessionTwo['token'], 'GET', '/api/user')->assertOk();

    expect(imEvent('platform.impersonation.ended')->actor_id)->toBe($first->id);
});

it('stops writes but not reads when the hotel is suspended mid-session', function () {
    $hotel = makeHotel();
    $guest = imGuest($hotel);
    $token = imStart($this, imSuperAdmin(), adminOfHotel($hotel))['token'];

    imRequest($this, $token, 'PUT', "/api/guest/{$guest->id}", ['first_name' => 'Before'])->assertOk();

    spSuspend($hotel);

    imRequest($this, $token, 'GET', "/api/guest/{$guest->id}")->assertOk();
    imRequest($this, $token, 'PUT', "/api/guest/{$guest->id}", ['first_name' => 'After'])->assertForbidden();

    expect($guest->fresh()->first_name)->toBe('Before');
});

it('leaves a session running when the super admin\'s own tokens are revoked', function () {
    $superAdmin = imSuperAdmin();
    $session = imStart($this, $superAdmin, adminOfHotel(makeHotel()));

    $superAdmin->tokens()->delete();

    imRequest($this, $session['token'], 'GET', '/api/user')->assertOk();
});

/*
| Changed after code review.
*/

it('records the hotel settings a session clears by deleting a team or category', function (string $column, Closure $make, string $path) {
    $hotel = makeHotel();
    $record = $make($hotel);
    Hotel::whereKey($hotel->id)->update([$column => $record->id]);
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $token = imStart($this, $superAdmin, $target)['token'];

    imRequest($this, $token, 'DELETE', "{$path}/{$record->id}")->assertSuccessful();

    $event = imEvent('hotel.updated');

    expect($hotel->fresh()->{$column})->toBeNull()
        ->and(imEvents('hotel.updated'))->toBe(1)
        ->and($event->subject_id)->toBe($hotel->id)
        ->and($event->hotel_id)->toBe($hotel->id)
        ->and($event->actor_id)->toBe($superAdmin->id)
        ->and($event->changes)->toBeNull()
        ->and($event->context['changed_fields'])->toBe([$column]);
})->with([
    'housekeeping team' => ['housekeeping_team_id', fn (Hotel $h) => Team::create(['hotel_id' => $h->id, 'name' => 'Cleared']), '/api/team'],
    'maintenance team' => ['maintenance_team_id', fn (Hotel $h) => Team::create(['hotel_id' => $h->id, 'name' => 'Cleared']), '/api/team'],
    'cleaning category' => ['cleaning_task_category_id', fn (Hotel $h) => TaskCategory::create(['hotel_id' => $h->id, 'name' => 'Cleared']), '/api/task-category'],
]);

it('records no settings event when the deleted record is not a hotel default, or outside a session', function () {
    $hotel = makeHotel();
    $unused = Team::create(['hotel_id' => $hotel->id, 'name' => 'Unused']);
    $used = Team::create(['hotel_id' => $hotel->id, 'name' => 'Used']);
    Hotel::whereKey($hotel->id)->update(['housekeeping_team_id' => $used->id]);
    $target = adminOfHotel($hotel);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    imRequest($this, $token, 'DELETE', "/api/team/{$unused->id}")->assertSuccessful();
    imOwnRequest($this, $target, 'DELETE', "/api/team/{$used->id}")->assertSuccessful();

    expect(imEvents('hotel.updated'))->toBe(0);
});

it('names the super admin even if they are deleted during the request', function () {
    $hotel = makeHotel();
    $superAdmin = imSuperAdmin();
    $target = adminOfHotel($hotel);
    $token = PersonalAccessToken::findToken(imStart($this, $superAdmin, $target)['token']);
    $superAdminId = $superAdmin->id;

    app('auth')->forgetGuards();
    $superAdmin->delete();
    Auth::setUser($target->withAccessToken($token));

    EventLogger::recordPlatform('platform.test.happened', $target);

    $event = imEvent('platform.test.happened');

    expect($event->actor_id)->toBe($superAdminId)
        ->and($event->context['impersonated_user_id'])->toBe($target->id);
});

it('refuses to change a user or issue a token during a session, whichever code tries', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $staff = User::factory()->create(['hotel_id' => $hotel->id]);
    $token = PersonalAccessToken::findToken(imStart($this, imSuperAdmin(), $target)['token']);
    $name = $target->name;

    app('auth')->forgetGuards();
    Auth::setUser($target->withAccessToken($token));

    $attempts = [
        fn () => $target->update(['password' => 'A-new-Password1!']),
        fn () => $staff->update(['team_id' => null, 'name' => 'Changed']),
        fn () => $staff->delete(),
        fn () => User::factory()->create(),
        fn () => $target->createToken('lasting'),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(DomainRuleException::class, CurrentImpersonation::REFUSAL);
    }

    app('auth')->forgetGuards();

    expect($target->fresh()->name)->toBe($name)
        ->and(User::find($staff->id))->not->toBeNull()
        ->and(PersonalAccessToken::where('name', 'lasting')->count())->toBe(0);
});

it('lists the same writes in a capture with or without a session', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    $token = PersonalAccessToken::findToken(imStart($this, imSuperAdmin(), $target)['token']);

    app('auth')->forgetGuards();

    $capture = fn (string $name) => EventLogger::capture(fn () => Team::create(['hotel_id' => $hotel->id, 'name' => $name]))[1];

    $outside = $capture('Outside');

    Auth::setUser($target->withAccessToken($token));

    $inside = $capture('Inside');

    expect(collect($inside)->map(fn (array $write) => [$write['event_type'], $write['audited']])->all())
        ->toBe(collect($outside)->map(fn (array $write) => [$write['event_type'], $write['audited']])->all())
        ->and(imEvents('team.created'))->toBe(1);
});

it('does not authenticate anyone just by checking for a session', function () {
    $target = adminOfHotel(makeHotel());
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    imRequest($this, $token, 'GET', '/api/user')->assertOk();

    app('auth')->forgetGuards();

    // The last request still holds the Bearer token, so resolving the guard
    // here would sign the session's user in, and the next request would get them.
    expect(CurrentImpersonation::token())->toBeNull()
        ->and(app('auth')->hasUser())->toBeFalse();
});

it('shows a session the user\'s own device, and pairing stays refused', function () {
    $hotel = makeHotel();
    $target = adminOfHotel($hotel);
    WhatsAppDevice::create(['user_id' => $target->id, 'phone_number' => '201000000041', 'hotel_id' => $hotel->id, 'wa_user_id' => 'wa-41', 'status' => 'active']);
    $token = imStart($this, imSuperAdmin(), $target)['token'];

    imRequest($this, $token, 'GET', '/api/whatsapp-device')
        ->assertOk()
        ->assertJsonPath('body.device.wa_user_id', 'wa-41');
    imRequest($this, $token, 'POST', '/api/connect')->assertForbidden();
});
