<?php

use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Spec 024: suspending and reactivating through /api/superadmin, and finding
| what is suspended.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function saSuperAdmin(): User
{
    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

function saCall($test, ?User $user, string $uri, array $payload = [])
{
    return $test->withHeaders(apiHeaders())->actingAs($user ?? saSuperAdmin(), 'sanctum')->postJson($uri, $payload);
}

function saRecords(): array
{
    [$account, $hotel] = spAccount();

    return ['account' => $account, 'hotel' => $hotel];
}

function saPath(string $kind, Hotel|HotelGroup $record, string $action): string
{
    return "/api/superadmin/{$kind}s/{$record->id}/{$action}";
}

it('suspends with a reason, and records who, when and why', function (string $kind) {
    $record = saRecords()[$kind];
    $admin = saSuperAdmin();

    saCall($this, $admin, saPath($kind, $record, 'suspend'), ['reason' => 'Unpaid invoice'])
        ->assertOk()
        ->assertJsonPath('body.id', $record->id)
        ->assertJsonPath('body.suspended_by', $admin->id)
        ->assertJsonPath('body.suspended_reason', 'Unpaid invoice');

    expect($record->fresh()->suspended_at)->not->toBeNull();

    $event = EventLog::withoutGlobalScope('hotel')->where('event_type', "platform.{$kind}.suspended")->sole();

    expect($event)
        ->hotel_id->toBeNull()
        ->actor_id->toBe($admin->id)
        ->subject_id->toBe($record->id)
        ->and($event->context['reason'])->toBe('Unpaid invoice');
})->with(['account', 'hotel']);

it('requires a reason', function (string $kind, array $payload) {
    $record = saRecords()[$kind];

    saCall($this, null, saPath($kind, $record, 'suspend'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect($record->fresh()->suspended_at)->toBeNull();
})->with(['account', 'hotel'])->with([
    'missing' => [[]],
    'empty' => [['reason' => '']],
    'too long' => [['reason' => str_repeat('x', 1001)]],
]);

it('refuses to suspend twice, and keeps the original details', function (string $kind) {
    $record = saRecords()[$kind];
    $first = saSuperAdmin();

    saCall($this, $first, saPath($kind, $record, 'suspend'), ['reason' => 'First'])->assertOk();
    saCall($this, null, saPath($kind, $record, 'suspend'), ['reason' => 'Second'])->assertUnprocessable();

    expect($record->fresh())
        ->suspended_reason->toBe('First')
        ->suspended_by->toBe($first->id)
        ->and(EventLog::withoutGlobalScope('hotel')->where('event_type', "platform.{$kind}.suspended")->count())->toBe(1);
})->with(['account', 'hotel']);

it('gets 404 for a soft-deleted record', function (string $kind) {
    [, $hotel] = spAccount();
    $empty = HotelGroup::create(['name' => 'Empty', 'slug' => 'empty']);
    $record = $kind === 'hotel' ? $hotel : $empty;
    $record->delete();

    saCall($this, null, saPath($kind, $record, 'suspend'), ['reason' => 'X'])->assertNotFound();
})->with(['account', 'hotel']);

it('refuses anyone but a super admin', function (string $kind, string $action, UserRole $role) {
    $record = saRecords()[$kind];
    $hotel = $kind === 'hotel' ? $record : $record->hotels()->first();
    $caller = spUser($hotel, role: $role);

    saCall($this, $caller, saPath($kind, $record, $action), ['reason' => 'X'])->assertForbidden();

    expect($record->fresh()->suspended_at)->toBeNull();
})->with(['account', 'hotel'])->with(['suspend', 'reactivate'])->with([UserRole::ADMIN, UserRole::EMPLOYEE]);

it('reactivates, clearing the details', function (string $kind) {
    $record = saRecords()[$kind];
    spSuspend($record);
    $admin = saSuperAdmin();

    saCall($this, $admin, saPath($kind, $record, 'reactivate'))
        ->assertOk()
        ->assertJsonPath('body.suspended_at', null)
        ->assertJsonPath('body.suspended_reason', null);

    expect($record->fresh())
        ->suspended_at->toBeNull()
        ->suspended_by->toBeNull()
        ->suspended_reason->toBeNull();

    expect(EventLog::withoutGlobalScope('hotel')->where('event_type', "platform.{$kind}.reactivated")->sole())
        ->hotel_id->toBeNull()
        ->actor_id->toBe($admin->id);
})->with(['account', 'hotel']);

it('refuses to reactivate what is not suspended in its own right, with no event', function () {
    [$account, $hotel] = spAccount();
    spSuspend($account);

    saCall($this, null, saPath('hotel', $hotel, 'reactivate'))->assertUnprocessable();
    spReactivate($account);
    saCall($this, null, saPath('account', $account, 'reactivate'))->assertUnprocessable();

    expect(EventLog::withoutGlobalScope('hotel')->where('event_type', 'like', 'platform.%.reactivated')->count())->toBe(0);
});

it('keeps the two levels independent', function () {
    [$account, $hotel] = spAccount();
    $owner = spUser($hotel);
    spSuspend($account);

    // A hotel can be suspended inside a suspended account.
    saCall($this, null, saPath('hotel', $hotel, 'suspend'), ['reason' => 'Own'])->assertOk();
    expect($account->fresh()->suspended_reason)->toBe('Unpaid invoice');

    spRequest($this, $owner, 'GET', '/api/user')->assertJsonPath('body.scope', 'account');

    // And stays suspended after the account returns.
    saCall($this, null, saPath('account', $account, 'reactivate'))->assertOk();
    expect($hotel->fresh()->suspended_reason)->toBe('Own');

    spRequest($this, $owner, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'hotel');

    // Reactivating a hotel inside a suspended account leaves it suspended.
    spSuspend($account);
    saCall($this, null, saPath('hotel', $hotel, 'reactivate'))->assertOk();
    expect($hotel->fresh()->isSuspended())->toBeTrue();

    spRequest($this, $owner, 'GET', '/api/user')->assertForbidden()->assertJsonPath('body.scope', 'account');
});

it('filters both lists by suspension', function (string $value, bool $wantSuspended) {
    [$suspendedAccount, $viaAccount] = spAccount('stopped');
    [, $own, $active] = spAccount('running');
    spSuspend($suspendedAccount);
    spSuspend($own);
    $activeAccount = $active->hotelGroup;

    $accounts = $this->withHeaders(apiHeaders())->actingAs(saSuperAdmin(), 'sanctum')
        ->getJson("/api/superadmin/accounts?filter[suspended]={$value}")->assertOk();

    expect(collect($accounts->json('body.data'))->pluck('id')->all())
        ->toBe([$wantSuspended ? $suspendedAccount->id : $activeAccount->id]);

    $hotels = $this->getJson("/api/superadmin/hotels?filter[suspended]={$value}")->assertOk();
    $ids = collect($hotels->json('body.data'))->pluck('id');

    expect($ids->contains($viaAccount->id))->toBe($wantSuspended)
        ->and($ids->contains($own->id))->toBe($wantSuspended)
        ->and($ids->contains($active->id))->toBe(! $wantSuspended);
})->with([
    'true' => ['true', true],
    'one' => ['1', true],
    'false' => ['false', false],
    'zero' => ['0', false],
]);

it('refuses a bad suspended filter and sorting by it', function (string $list, string $query, string $error) {
    $this->withHeaders(apiHeaders())->actingAs(saSuperAdmin(), 'sanctum')
        ->getJson("/api/superadmin/{$list}?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with(['accounts', 'hotels'])->with([
    'not a boolean' => ['filter[suspended]=banana', 'filter.suspended'],
    'an array' => ['filter[suspended][]=true', 'filter.suspended'],
    'sort' => ['sort=suspended', 'sort'],
]);

it('shows a hotel counted as suspended through its account, with no query per row', function () {
    [$account, $a, $b] = spAccount();
    spSuspend($account);
    $admin = saSuperAdmin();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/superadmin/hotels/{$a->id}")
        ->assertOk()
        ->assertJsonPath('body.is_suspended', true)
        ->assertJsonPath('body.suspended_at', null);

    DB::enableQueryLog();

    $response = $this->getJson('/api/superadmin/hotels?filter[suspended]=true')->assertOk();

    expect(collect($response->json('body.data'))->pluck('is_suspended')->all())->toBe([true, true])
        ->and(collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^select [^(]*from "hotel_groups"/', $sql))->count())->toBe(0);
});
