<?php

use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
| Spec 023 Story 6: every administrative write records one event with no hotel
| attached, and no hotel user can ever read it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
});

function platformRows()
{
    return EventLog::withoutGlobalScopes()->where('event_type', 'like', 'platform.%');
}

it('never attaches a hotel to a platform event, even with a current hotel set', function () {
    $hotel = makeHotel();
    $guest = gsGuest($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $this->actingAs($superAdmin, 'sanctum');

    TenantContext::setCurrentHotelId($hotel->id);

    EventLogger::recordPlatform('platform.test.happened', $guest);

    $row = platformRows()->sole();

    expect($row->hotel_id)->toBeNull()
        ->and($row->actor_id)->toBe($superAdmin->id)
        ->and($row->subject_id)->toBe($guest->id)
        // The scope is left as it was found.
        ->and(TenantContext::currentHotelId())->toBe($hotel->id);
});

it('hides a platform event about a hotel\'s own record from that hotel', function () {
    $hotel = makeHotel();
    $guest = gsGuest($hotel);
    $this->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum');

    EventLogger::recordPlatform('platform.test.happened', $guest);
    app('auth')->forgetGuards();

    $types = collect($this->withHeaders(apiHeaders())->actingAs(adminOfHotel($hotel), 'sanctum')
        ->getJson("/api/history/guest/{$guest->id}")->assertOk()->json('body.data'))->pluck('event_type');

    expect($types)->not->toContain('platform.test.happened');

    $visible = TenantContext::runForHotel($hotel->id, fn () => EventLog::query()->where('event_type', 'like', 'platform.%')->count());

    expect($visible)->toBe(0);
});

it('records exactly one named event for each administrative write', function (string $eventType, Closure $write) {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $account = HotelGroup::create(['name' => 'Acme', 'slug' => 'acme']);
    $hotel = Hotel::create(['hotel_group_id' => $account->id, 'name' => 'Harbor', 'slug' => 'harbor']);
    $empty = HotelGroup::create(['name' => 'Empty', 'slug' => 'empty']);
    $user = User::factory()->create(['hotel_id' => $hotel->id]);

    $request = $this->withHeaders(apiHeaders())->actingAs($superAdmin, 'sanctum');

    $write($request, compact('account', 'hotel', 'empty', 'user'))->assertSuccessful();

    $rows = platformRows()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->event_type)->toBe($eventType)
        ->and($rows->first()->hotel_id)->toBeNull()
        ->and($rows->first()->actor_id)->toBe($superAdmin->id);
})->with([
    'account created' => ['platform.account.created', fn ($r) => $r->postJson('/api/superadmin/accounts', ['name' => 'New', 'slug' => 'new'])],
    'account updated' => ['platform.account.updated', fn ($r, $x) => $r->putJson("/api/superadmin/accounts/{$x['account']->id}", ['name' => 'Renamed'])],
    'account deleted' => ['platform.account.deleted', fn ($r, $x) => $r->deleteJson("/api/superadmin/accounts/{$x['empty']->id}")],
    'hotel created' => ['platform.hotel.created', fn ($r, $x) => $r->postJson("/api/superadmin/accounts/{$x['account']->id}/hotels", ['name' => 'New', 'slug' => 'new'])],
    'hotel updated' => ['platform.hotel.updated', fn ($r, $x) => $r->putJson("/api/superadmin/hotels/{$x['hotel']->id}", ['name' => 'Renamed'])],
    'hotel deleted' => ['platform.hotel.deleted', fn ($r, $x) => $r->deleteJson("/api/superadmin/hotels/{$x['hotel']->id}")],
    'user created' => ['platform.user.created', fn ($r, $x) => $r->postJson('/api/superadmin/users', ['name' => 'New', 'email' => 'new@example.com', 'role' => 'employee', 'hotel_id' => $x['hotel']->id])],
    'user updated' => ['platform.user.updated', fn ($r, $x) => $r->putJson("/api/superadmin/users/{$x['user']->id}", ['name' => 'Renamed'])],
    'user access changed' => ['platform.user.access_changed', fn ($r, $x) => $r->putJson("/api/superadmin/users/{$x['user']->id}/access", ['role' => 'admin'])],
    'password reset sent' => ['platform.user.password_reset_sent', fn ($r, $x) => $r->postJson("/api/superadmin/users/{$x['user']->id}/password-reset")],
    'access revoked' => ['platform.user.access_revoked', fn ($r, $x) => $r->postJson("/api/superadmin/users/{$x['user']->id}/revoke-tokens")],
]);

it('keeps passwords, hashes and tokens out of platform events', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $hotel = makeHotel();
    $as = fn () => $this->withHeaders(apiHeaders())->actingAs($superAdmin, 'sanctum');

    $id = $as()->postJson('/api/superadmin/users', [
        'name' => 'New', 'email' => 'new@example.com', 'role' => 'admin', 'hotel_id' => $hotel->id, 'password' => 'Never-Stored1!',
    ])->assertCreated()->json('body.id');

    $user = User::find($id);

    $as()->putJson("/api/superadmin/users/{$id}", ['name' => 'Renamed', 'password' => 'Never-Stored1!'])->assertOk();

    $this->travel(61)->seconds();
    $as()->postJson("/api/superadmin/users/{$id}/password-reset")->assertOk();

    $sent = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$sent) {
        $sent = $notification->token;

        return true;
    });

    $stored = platformRows()->get()->map(fn (EventLog $row) => json_encode([$row->changes, $row->context]))->implode(' ');

    expect($stored)->not->toContain('Never-Stored1!')
        ->and($stored)->not->toContain($user->password)
        ->and($stored)->not->toContain($sent)
        ->and($stored)->not->toContain('password"');
});

it('writes no platform event for a tenant edit or a completed public reset', function () {
    $hotel = makeHotel();
    $staff = User::factory()->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(apiHeaders())->actingAs(adminOfHotel($hotel), 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['name' => 'Renamed'])->assertOk();

    $token = Password::broker('users')->createToken($staff);

    $this->withHeaders(apiHeaders())->postJson('/api/reset-password', [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'A-new-Password1!',
        'password_confirmation' => 'A-new-Password1!',
    ])->assertOk();

    expect(platformRows()->count())->toBe(0);
});
