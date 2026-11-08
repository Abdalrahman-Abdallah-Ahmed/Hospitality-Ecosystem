<?php

use App\Enums\MeterFeature;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Team;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Metering\MeteringService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

/*
| Spec 023: hotel administration under /api/superadmin.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function hotelAdmin($test)
{
    return $test->withHeaders(apiHeaders())
        ->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum');
}

function hotelAccount(string $slug = 'acme', array $attributes = []): HotelGroup
{
    return HotelGroup::create(['name' => Str::title($slug), 'slug' => $slug, ...$attributes]);
}

function hotelIn(HotelGroup $account, string $slug = 'harbor', array $attributes = []): Hotel
{
    return Hotel::create(['hotel_group_id' => $account->id, 'name' => Str::title($slug), 'slug' => $slug, ...$attributes]);
}

function propertyCount(HotelGroup $account): int
{
    return (int) UsageCounter::where('hotel_group_id', $account->id)
        ->where('feature_code', MeterFeature::PROPERTIES->value)
        ->where('period_start', MeteringService::periodStart(now()))
        ->value('used');
}

it('creates a hotel under an account with the operational defaults', function () {
    $account = hotelAccount();

    $body = hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", [
        'name' => 'Harbor',
        'slug' => 'harbor',
        'city' => 'Alexandria',
    ])->assertCreated()->json('body');

    expect($body['hotel_group_id'])->toBe($account->id)
        ->and($body)->not->toHaveKey('owner_id')
        ->and($body['city'])->toBe('Alexandria')
        ->and($body['is_active'])->toBeTrue();

    foreach (['housekeeping_team_id', 'cleaning_task_category_id', 'inspection_task_category_id', 'maintenance_team_id', 'maintenance_task_category_id'] as $field) {
        expect($body[$field])->not->toBeNull();
    }

    expect(EventLog::where('event_type', 'platform.hotel.created')->where('subject_id', $body['id'])->count())->toBe(1);
});

it('ignores client-sent owner and default team fields on a hotel create', function () {
    $account = hotelAccount();
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $other = makeHotel();
    $foreignTeam = Team::create(['hotel_id' => $other->id, 'name' => 'Theirs', 'is_active' => true]);

    $body = hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", [
        'name' => 'Harbor',
        'slug' => 'harbor',
        'owner_id' => $owner->id,
        'housekeeping_team_id' => $foreignTeam->id,
    ])->assertCreated()->json('body');

    expect($body)->not->toHaveKey('owner_id')
        ->and($body['housekeeping_team_id'])->not->toBe($foreignTeam->id);
});

it('takes the account defaults when currency and timezone are omitted', function () {
    $account = hotelAccount('acme', ['default_currency' => 'EGP', 'default_timezone' => 'Africa/Cairo']);

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", ['name' => 'Harbor', 'slug' => 'harbor'])
        ->assertCreated()
        ->assertJsonPath('body.currency', 'EGP')
        ->assertJsonPath('body.timezone', 'Africa/Cairo');
});

it('keeps a currency and timezone the request sends', function () {
    $account = hotelAccount('acme', ['default_currency' => 'EGP']);

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", [
        'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD', 'timezone' => 'Asia/Dubai',
    ])->assertCreated()
        ->assertJsonPath('body.currency', 'USD')
        ->assertJsonPath('body.timezone', 'Asia/Dubai');
});

it('rejects a hotel missing a name, or with an invalid currency or timezone', function () {
    $account = hotelAccount();

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", [
        'slug' => 'harbor', 'currency' => 'DOLLARS', 'timezone' => 'Mars/Base',
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'currency', 'timezone']);
});

it('refuses a slug a live hotel holds', function () {
    $account = hotelAccount();
    hotelIn($account);

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", ['name' => 'Copy', 'slug' => 'harbor'])
        ->assertStatus(422);
});

it('restores a soft-deleted hotel with the same slug in the same account', function () {
    $account = hotelAccount();
    $hotel = hotelIn($account, 'harbor', ['currency' => 'GBP', 'city' => 'Old City']);
    $guest = gsGuest($hotel);
    $hotel->delete();

    expect(propertyCount($account))->toBe(0);

    $body = hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", [
        'name' => 'Harbor Reborn',
        'slug' => 'harbor',
        'city' => 'New City',
    ])->assertCreated()->json('body');

    expect($body['id'])->toBe($hotel->id)
        ->and($body['name'])->toBe('Harbor Reborn')
        ->and($body['city'])->toBe('New City')
        // An omitted field keeps its value instead of taking the account default.
        ->and($body['currency'])->toBe('GBP')
        ->and($hotel->fresh()->trashed())->toBeFalse()
        ->and($guest->fresh()->hotel_id)->toBe($hotel->id)
        ->and(propertyCount($account))->toBe(1);

    $event = EventLog::where('event_type', 'platform.hotel.created')->where('subject_id', $hotel->id)->sole();
    expect($event->context['restored'])->toBeTrue();
});

it('will not restore or move a deleted hotel of another account', function () {
    $mine = hotelAccount('mine');
    $theirs = hotelAccount('theirs');
    $hotel = hotelIn($theirs);
    $guest = gsGuest($hotel);
    $hotel->delete();

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$mine->id}/hotels", ['name' => 'Harbor', 'slug' => 'harbor'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The slug has already been taken.');

    $stored = Hotel::withTrashed()->find($hotel->id);

    expect($stored->trashed())->toBeTrue()
        ->and($stored->hotel_group_id)->toBe($theirs->id)
        ->and($guest->fresh()->hotel_id)->toBe($hotel->id)
        ->and(Hotel::where('hotel_group_id', $mine->id)->count())->toBe(0);
});

it('answers 404 for a hotel created under a deleted account', function () {
    $account = hotelAccount();
    $account->delete();

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", ['name' => 'Harbor', 'slug' => 'harbor'])
        ->assertNotFound();
});

it('shows a hotel, and answers 404 for a deleted one', function () {
    $hotel = hotelIn(hotelAccount());

    hotelAdmin($this)->getJson("/api/superadmin/hotels/{$hotel->id}")->assertOk()->assertJsonPath('body.id', $hotel->id);

    $hotel->delete();

    hotelAdmin($this)->getJson("/api/superadmin/hotels/{$hotel->id}")->assertNotFound();
});

it('lists hotels across accounts and filters by account', function () {
    $a = hotelAccount('a');
    $b = hotelAccount('b');
    hotelIn($a, 'one');
    hotelIn($b, 'two');

    expect(hotelAdmin($this)->getJson('/api/superadmin/hotels')->assertOk()->json('body.data'))->toHaveCount(2);

    $rows = hotelAdmin($this)->getJson("/api/superadmin/hotels?filter[hotel_group_id]={$a->id}")->assertOk()->json('body.data');

    expect($rows)->toHaveCount(1)->and($rows[0]['slug'])->toBe('one');
});

it('refuses a malformed account filter rather than failing on the database', function () {
    hotelAdmin($this)->getJson('/api/superadmin/hotels?filter[hotel_group_id]=not-a-uuid')->assertStatus(422);
});

it('keeps a hotel create working when the seat recount fails', function () {
    $account = hotelAccount();
    $reported = Exceptions::fake();

    $this->partialMock(MeteringService::class, fn ($metering) => $metering
        ->shouldReceive('recountSeats')
        ->andReturnUsing(fn () => DB::statement('select * from no_such_table')));

    hotelAdmin($this)->postJson("/api/superadmin/accounts/{$account->id}/hotels", ['name' => 'Harbor', 'slug' => 'harbor'])
        ->assertCreated();

    $reported->assertReported(QueryException::class);
    expect(Hotel::where('slug', 'harbor')->exists())->toBeTrue();
});

it('edits only name, slug and account on a hotel', function () {
    $hotel = hotelIn(hotelAccount(), 'harbor', ['city' => 'Keep']);

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", [
        'name' => 'Renamed',
        'city' => 'Changed',
        'inspection_required' => true,
    ])->assertOk()->assertJsonPath('body.name', 'Renamed');

    $fresh = $hotel->fresh();

    expect($fresh->city)->toBe('Keep')
        ->and($fresh->inspection_required)->toBeFalse()
        ->and(EventLog::where('event_type', 'platform.hotel.updated')->where('subject_id', $hotel->id)->count())->toBe(1);
});

it('refuses a slug a deleted hotel holds on an edit, without a server error', function () {
    $account = hotelAccount();
    $hotel = hotelIn($account, 'harbor');
    hotelIn($account, 'gone')->delete();

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", ['slug' => 'gone'])->assertStatus(422);
    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", ['slug' => 'harbor'])->assertOk();
});

it('moves a hotel whose users are attached only by home hotel, with users and seats following', function () {
    $from = hotelAccount('from');
    $to = hotelAccount('to');
    $hotel = hotelIn($from);
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    expect(propertyCount($from))->toBe(1);

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", ['hotel_group_id' => $to->id])
        ->assertOk()
        ->assertJsonPath('body.hotel_group_id', $to->id);

    expect($owner->fresh()->account()->id)->toBe($to->id)
        ->and(propertyCount($from))->toBe(0)
        ->and(propertyCount($to))->toBe(1);
});

it('refuses each hotel move that would leave someone linked across accounts', function (Closure $arrange) {
    $from = hotelAccount('from');
    $to = hotelAccount('to');
    $hotel = hotelIn($from);
    $arrange($hotel, $from);

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", ['hotel_group_id' => $to->id])
        ->assertStatus(422);

    expect($hotel->fresh()->hotel_group_id)->toBe($from->id)
        ->and(EventLog::where('event_type', 'platform.hotel.updated')->count())->toBe(0);
})->with([
    'the hotel has a membership' => [function (Hotel $hotel) {
        $member = User::factory()->create();
        DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $member->id, 'hotel_id' => $hotel->id, 'role' => 'employee']);
    }],
    'a home-hotel user is attached to the account' => [function (Hotel $hotel, HotelGroup $from) {
        User::factory()->create(['hotel_id' => $hotel->id, 'hotel_group_id' => $from->id]);
    }],
    'a home-hotel user has a membership elsewhere' => [function (Hotel $hotel, HotelGroup $from) {
        $user = User::factory()->create(['hotel_id' => $hotel->id]);
        $other = hotelIn($from, 'other');
        DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'hotel_id' => $other->id, 'role' => 'employee']);
    }],
]);

it('moves a self-registered customer\'s hotel once their account-wide role is cleared', function () {
    Notification::fake();
    $to = hotelAccount('to');
    $existing = User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $to->id, 'group_role' => 'owner']);

    $body = $this->withHeaders(apiHeaders())->postJson('/api/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'hotel' => ['name' => 'Alice Hotel', 'city' => 'Cairo'],
    ])->assertCreated()->json('body');
    $customer = User::findOrFail($body['user']['id']);
    $hotelId = $body['hotel']['id'];

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotelId}", ['hotel_group_id' => $to->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This hotel has staff attached to its account. Clear their account-wide role before moving it to another account.');

    hotelAdmin($this)->putJson("/api/superadmin/users/{$customer->id}/access", ['group_role' => null])->assertOk();
    expect($customer->fresh()->accessibleHotelIds())->toBe([$hotelId]);

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotelId}", ['hotel_group_id' => $to->id])->assertOk();

    $fresh = $customer->fresh();

    expect($fresh->account()->id)->toBe($to->id)
        ->and($fresh->isOwner())->toBeFalse()
        ->and($fresh->accessibleHotelIds())->toBe([$hotelId])
        ->and($existing->fresh()->accessibleHotelIds())->toBe([$hotelId]);

    hotelAdmin($this)->putJson("/api/superadmin/users/{$customer->id}/access", ['group_role' => 'owner'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This account already has an owner.');
});

it('moves a hotel out of one owner\'s reach and into another\'s, with no further step', function () {
    $from = hotelAccount('from');
    $to = hotelAccount('to');
    $home = hotelIn($from, 'home');
    $moved = hotelIn($from, 'moved');
    $sourceOwner = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $home->id, 'hotel_group_id' => $from->id, 'group_role' => 'owner']);
    $destinationOwner = User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $to->id, 'group_role' => 'owner']);

    expect($sourceOwner->accessibleHotelIds())->toContain($moved->id);

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$moved->id}", ['hotel_group_id' => $to->id])->assertOk();

    expect($sourceOwner->fresh()->accessibleHotelIds())->toBe([$home->id])
        ->and($destinationOwner->fresh()->accessibleHotelIds())->toBe([$moved->id]);
});

it('refuses to move a hotel into a deleted account', function () {
    $to = hotelAccount('to');
    $to->delete();
    $hotel = hotelIn(hotelAccount('from'));

    hotelAdmin($this)->putJson("/api/superadmin/hotels/{$hotel->id}", ['hotel_group_id' => $to->id])->assertStatus(422);
});

it('soft-deletes a hotel and recounts its account', function () {
    $account = hotelAccount();
    $hotel = hotelIn($account);

    hotelAdmin($this)->deleteJson("/api/superadmin/hotels/{$hotel->id}")->assertOk();

    expect(Hotel::withTrashed()->find($hotel->id)->trashed())->toBeTrue()
        ->and(propertyCount($account))->toBe(0)
        ->and(EventLog::where('event_type', 'platform.hotel.deleted')->where('subject_id', $hotel->id)->count())->toBe(1);
});
