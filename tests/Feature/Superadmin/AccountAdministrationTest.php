<?php

use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Spec 023: account administration under /api/superadmin.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function accountAdmin($test)
{
    return $test->withHeaders(apiHeaders())
        ->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum');
}

function accountNamed(string $slug, array $attributes = []): HotelGroup
{
    return HotelGroup::create(['name' => Str::title($slug), 'slug' => $slug, ...$attributes]);
}

const ACCOUNT_FIELDS = [
    'id', 'name', 'slug', 'country_code', 'default_currency', 'default_timezone',
    'hotels_count', 'users_count', 'created_at', 'updated_at', 'suspended_at', 'suspended_by', 'suspended_reason'];

it('creates an account with only its public fields in the response', function () {
    $body = accountAdmin($this)->postJson('/api/superadmin/accounts', [
        'name' => 'Acme Hotels',
        'slug' => 'acme',
        'country_code' => 'EG',
        'default_currency' => 'EGP',
        'default_timezone' => 'Africa/Cairo',
    ])->assertCreated()->json('body');

    expect(array_keys($body))->toEqualCanonicalizing(ACCOUNT_FIELDS)
        ->and($body['hotels_count'])->toBe(0)
        ->and($body['users_count'])->toBe(0)
        ->and($body['default_currency'])->toBe('EGP');

    expect(EventLog::where('event_type', 'platform.account.created')->where('subject_id', $body['id'])->count())->toBe(1);
});

it('fills account defaults the request leaves out', function () {
    accountAdmin($this)->postJson('/api/superadmin/accounts', ['name' => 'Acme', 'slug' => 'acme'])
        ->assertCreated()
        ->assertJsonPath('body.default_currency', 'EUR')
        ->assertJsonPath('body.default_timezone', 'UTC');
});

it('ignores contract fields on an account create', function () {
    $body = accountAdmin($this)->postJson('/api/superadmin/accounts', [
        'name' => 'Acme',
        'slug' => 'acme',
        'contract_value_monthly' => 4242.42,
        'contract_currency' => 'EUR',
    ])->assertCreated()->json('body');

    $account = HotelGroup::find($body['id']);

    expect($account->contract_value_monthly)->toBeNull()
        ->and($account->contract_currency)->toBeNull()
        ->and(json_encode($body))->not->toContain('contract');
});

it('refuses an account slug a soft-deleted account still holds, without a server error', function () {
    accountNamed('taken')->delete();

    accountAdmin($this)->postJson('/api/superadmin/accounts', ['name' => 'New', 'slug' => 'taken'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

it('counts hotels and each kind of attached user once on an account', function () {
    $account = accountNamed('acme');
    $hotel = Hotel::create(['hotel_group_id' => $account->id, 'name' => 'A', 'slug' => 'a']);
    Hotel::create(['hotel_group_id' => $account->id, 'name' => 'Gone', 'slug' => 'gone'])->delete();

    User::factory()->create(['hotel_group_id' => $account->id]);
    User::factory()->create(['hotel_id' => $hotel->id]);
    $member = User::factory()->create();
    DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $member->id, 'hotel_id' => $hotel->id, 'role' => 'employee']);

    // All three routes at once still counts as one user.
    $everything = User::factory()->create(['hotel_group_id' => $account->id, 'hotel_id' => $hotel->id]);
    DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $everything->id, 'hotel_id' => $hotel->id, 'role' => 'employee']);

    // Neither of these belongs to the account's count.
    User::factory()->role(UserRole::SUPER_ADMIN)->create(['hotel_group_id' => $account->id]);
    User::factory()->create();

    accountAdmin($this)->getJson("/api/superadmin/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('body.hotels_count', 1)
        ->assertJsonPath('body.users_count', 4);
});

it('lists accounts with their counts', function () {
    $account = accountNamed('acme');
    Hotel::create(['hotel_group_id' => $account->id, 'name' => 'A', 'slug' => 'a']);

    $rows = accountAdmin($this)->getJson('/api/superadmin/accounts?sort=name')->assertOk()->json('body.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['hotels_count'])->toBe(1)
        ->and($rows[0]['users_count'])->toBe(0)
        ->and(array_keys($rows[0]))->toEqualCanonicalizing(ACCOUNT_FIELDS);
});

it('refuses filtering or sorting accounts on a contract field, and search cannot match one', function (string $query) {
    accountAdmin($this)->getJson('/api/superadmin/accounts?'.$query)->assertStatus(422);
})->with([
    'filter by value' => 'filter[contract_value_monthly]=10',
    'filter by currency' => 'filter[contract_currency]=EUR',
    'sort by value' => 'sort=contract_value_monthly',
    'sort by currency' => 'sort=-contract_currency',
    'malformed id' => 'filter[id]=abc',
]);

it('does not match a contract currency in an account search', function () {
    accountNamed('acme', ['contract_currency' => 'XYZ']);

    expect(accountAdmin($this)->getJson('/api/superadmin/accounts?search=XYZ')->assertOk()->json('body.data'))->toBeEmpty();
    expect(accountAdmin($this)->getJson('/api/superadmin/accounts?search=acme')->assertOk()->json('body.data'))->toHaveCount(1);
});

it('edits an account, ignores contract fields, and returns its counts', function () {
    $account = accountNamed('acme', ['contract_currency' => 'EUR']);

    accountAdmin($this)->putJson("/api/superadmin/accounts/{$account->id}", [
        'name' => 'Renamed',
        'contract_currency' => 'USD',
    ])->assertOk()
        ->assertJsonPath('body.name', 'Renamed')
        ->assertJsonPath('body.hotels_count', 0)
        ->assertJsonPath('body.users_count', 0);

    expect($account->fresh()->contract_currency)->toBe('EUR');

    $event = EventLog::where('event_type', 'platform.account.updated')->where('subject_id', $account->id)->sole();
    expect($event->changes['name'])->toBe(['from' => 'Acme', 'to' => 'Renamed']);
});

it('records no event for an account edit that changes nothing', function () {
    $account = accountNamed('acme');

    accountAdmin($this)->putJson("/api/superadmin/accounts/{$account->id}", ['name' => 'Acme'])->assertOk();

    expect(EventLog::where('event_type', 'platform.account.updated')->count())->toBe(0);
});

it('lets an account keep its own slug, and refuses one a deleted account holds', function () {
    $account = accountNamed('acme');
    accountNamed('taken')->delete();

    accountAdmin($this)->putJson("/api/superadmin/accounts/{$account->id}", ['slug' => 'acme'])->assertOk();
    accountAdmin($this)->putJson("/api/superadmin/accounts/{$account->id}", ['slug' => 'taken'])->assertStatus(422);
});

it('refuses to delete an account with a live hotel', function () {
    $account = accountNamed('acme');
    Hotel::create(['hotel_group_id' => $account->id, 'name' => 'A', 'slug' => 'a']);

    accountAdmin($this)->deleteJson("/api/superadmin/accounts/{$account->id}")->assertStatus(422);

    expect($account->fresh()->trashed())->toBeFalse()
        ->and(EventLog::where('event_type', 'platform.account.deleted')->count())->toBe(0);
});

it('soft-deletes an account with no hotels or only deleted ones', function () {
    $empty = accountNamed('empty');
    $withDeleted = accountNamed('with-deleted');
    Hotel::create(['hotel_group_id' => $withDeleted->id, 'name' => 'A', 'slug' => 'a'])->delete();

    accountAdmin($this)->deleteJson("/api/superadmin/accounts/{$empty->id}")->assertOk();
    accountAdmin($this)->deleteJson("/api/superadmin/accounts/{$withDeleted->id}")->assertOk();

    expect(HotelGroup::onlyTrashed()->count())->toBe(2)
        ->and(EventLog::where('event_type', 'platform.account.deleted')->count())->toBe(2);

    accountAdmin($this)->getJson("/api/superadmin/accounts/{$empty->id}")->assertNotFound();
});
