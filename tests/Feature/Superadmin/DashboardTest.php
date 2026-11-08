<?php

use App\Enums\AiAgentName;
use App\Enums\GroupRole;
use App\Enums\MeterFeature;
use App\Enums\UserRole;
use App\Http\Requests\Superadmin\SuperadminDashboardRequest;
use App\Models\AiUsageLog;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Metering\MeteringService;
use App\Services\Reports\PlatformSummary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Spec 026: the super admin dashboard. One file, because one controller serves
| all three sections and they share the window and the cache.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    $this->travelTo(Carbon::parse('2026-11-08 12:00:00'));
});

dataset('dashboard sections', ['counts', 'usage', 'activity']);

function dashboardRead($test, string $section, array $query = [], array $headers = []): TestResponse
{
    $superAdmin = User::where('role', UserRole::SUPER_ADMIN)->first()
        ?? User::factory()->role(UserRole::SUPER_ADMIN)->create();

    return $test->withHeaders([...apiHeaders(), ...$headers])->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/superadmin/dashboard/{$section}".($query ? '?'.http_build_query($query) : ''));
}

function dashboardAccount(string $name = 'Account'): HotelGroup
{
    return HotelGroup::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6))]);
}

function dashboardHotel(?HotelGroup $account = null, string $name = 'Hotel'): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'currency' => 'USD',
        'hotel_group_id' => ($account ?? dashboardAccount($name))->id,
    ]);
}

function dashboardSuspend(HotelGroup|Hotel $record): void
{
    $record->forceFill(['suspended_at' => now()])->saveQuietly();
}

function dashboardCreatedAt(Reservation|Booking|HotelGroup|User $record, string $at): void
{
    $record->forceFill(['created_at' => Carbon::parse($at)])->saveQuietly();
}

function dashboardCost(?string $accountId, ?string $hotelId, float $cost, bool $estimated = false, ?string $at = null): AiUsageLog
{
    return AiUsageLog::create([
        'hotel_group_id' => $accountId,
        'hotel_id' => $hotelId,
        'agent' => 'GuestConciergeAgent',
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'operation' => 'chat',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'cached_tokens' => 0,
        'cost_usd' => $cost,
        'cost_is_estimated' => $estimated,
        'trigger_kind' => 'guest_message',
        'capability' => AiAgentName::CONCIERGE->value,
        'occurred_at' => $at ? Carbon::parse($at) : now(),
    ]);
}

function dashboardReservation(Hotel $hotel, array $overrides = []): Reservation
{
    return tap(tenantOwnedModelFactories()[Reservation::class]($hotel), fn (Reservation $reservation) => $overrides && $reservation->forceFill($overrides)->saveQuietly());
}

function dashboardBooking(Hotel $hotel): Booking
{
    return tenantOwnedModelFactories()[Booking::class]($hotel);
}

function dashboardQueries(callable $run): int
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function dashboardRow(TestResponse $response, string $list, string $key, string $id): array
{
    return collect($response->json("body.{$list}"))->firstWhere($key, $id);
}

/*
| Story 1: counts.
*/

it('gives every counts field', function () {
    dashboardRead($this, 'counts')->assertOk()
        ->assertJsonPath('code', 200)
        ->assertJsonStructure(['body' => [
            'accounts' => ['active', 'suspended'],
            'hotels' => ['active', 'suspended'],
            'users' => ['active', 'suspended'],
            'signups' => [['date', 'accounts', 'users']],
            'from', 'to', 'cached_at',
        ]]);
});

it('leaves super admins out of every user figure', function () {
    User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['users'])->toBe(['active' => 0, 'suspended' => 0])
        ->and(array_sum(array_column($body['signups'], 'users')))->toBe(0);
});

it('keeps an account active when only one of its hotels is suspended', function () {
    $account = dashboardAccount();
    dashboardSuspend(dashboardHotel($account));
    dashboardHotel($account);

    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['accounts'])->toBe(['active' => 1, 'suspended' => 0])
        ->and($body['hotels'])->toBe(['active' => 1, 'suspended' => 1]);
});

it('counts a suspended account and all its hotels as suspended', function () {
    $account = dashboardAccount();
    dashboardHotel($account);
    dashboardHotel($account);
    dashboardSuspend($account);

    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['accounts'])->toBe(['active' => 0, 'suspended' => 1])
        ->and($body['hotels'])->toBe(['active' => 0, 'suspended' => 2]);
});

it('counts a registration as one account and one user that day', function () {
    Notification::fake();

    $this->withHeaders(apiHeaders())->postJson('/api/register', [
        'name' => 'New Customer',
        'email' => 'new@example.com',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'hotel' => ['name' => 'New Hotel', 'city' => 'Cairo'],
    ])->assertCreated();

    $row = collect(dashboardRead($this, 'counts')->json('body.signups'))->firstWhere('date', '2026-11-08');

    expect($row)->toBe(['date' => '2026-11-08', 'accounts' => 1, 'users' => 1]);
});

it('lists every day of the window, in order, with zeros', function () {
    dashboardCreatedAt(dashboardAccount(), '2026-11-02 09:00:00');

    $signups = dashboardRead($this, 'counts', ['from' => '2026-11-01', 'to' => '2026-11-03'])->json('body.signups');

    expect($signups)->toBe([
        ['date' => '2026-11-01', 'accounts' => 0, 'users' => 0],
        ['date' => '2026-11-02', 'accounts' => 1, 'users' => 0],
        ['date' => '2026-11-03', 'accounts' => 0, 'users' => 0],
    ]);
});

it('takes a deleted account out of the counts but keeps its signup', function () {
    $account = dashboardAccount();
    $account->delete();

    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['accounts'])->toBe(['active' => 0, 'suspended' => 0])
        ->and(collect($body['signups'])->firstWhere('date', '2026-11-08')['accounts'])->toBe(1);
});

it('does not count a deleted hotel', function () {
    dashboardHotel()->delete();

    expect(dashboardRead($this, 'counts')->json('body.hotels'))->toBe(['active' => 0, 'suspended' => 0]);
});

it('ignores is_active when counting active and suspended', function () {
    $account = dashboardAccount();
    $account->update(['is_active' => false]);
    dashboardHotel($account)->update(['is_active' => false]);

    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['accounts'])->toBe(['active' => 1, 'suspended' => 0])
        ->and($body['hotels'])->toBe(['active' => 1, 'suspended' => 0]);
});

it('counts an account with no hotels', function () {
    dashboardAccount();

    expect(dashboardRead($this, 'counts')->json('body.accounts'))->toBe(['active' => 1, 'suspended' => 0]);
});

it('gives zeros on an empty platform', function () {
    $body = dashboardRead($this, 'counts')->json('body');

    expect($body['accounts'])->toBe(['active' => 0, 'suspended' => 0])
        ->and($body['hotels'])->toBe(['active' => 0, 'suspended' => 0])
        ->and($body['users'])->toBe(['active' => 0, 'suspended' => 0])
        ->and($body['signups'])->toHaveCount(30)
        ->and(array_sum(array_column($body['signups'], 'accounts')))->toBe(0);

    expect(dashboardRead($this, 'usage')->json('body.accounts'))->toBe([])
        ->and(dashboardRead($this, 'activity')->json('body.hotels'))->toBe([]);
});

it('shows current accounts and hotels whatever the window', function () {
    dashboardHotel();

    $old = dashboardRead($this, 'counts', ['from' => '2026-01-01', 'to' => '2026-01-31'])->json('body');

    expect($old['accounts'])->toBe(['active' => 1, 'suspended' => 0])
        ->and($old['hotels'])->toBe(['active' => 1, 'suspended' => 0])
        ->and(array_sum(array_column($old['signups'], 'accounts')))->toBe(0);
});

/*
| Story 2: a user counts as suspended exactly when login refuses them. Each row
| asks the real login as well as the count, so the count's SQL can't drift away
| from HotelAccess without one of these failing.
*/

dataset('kinds of user', [
    'owner, home hotel suspended, another account hotel active, no membership row' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        dashboardHotel($account);
        dashboardSuspend($home);

        return ['hotel_id' => $home->id, 'hotel_group_id' => $account->id, 'group_role' => GroupRole::OWNER, 'role' => UserRole::ADMIN];
    }, false],
    'owner, every account hotel suspended' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        $other = dashboardHotel($account);
        dashboardSuspend($home);
        dashboardSuspend($other);

        return ['hotel_id' => $home->id, 'hotel_group_id' => $account->id, 'group_role' => GroupRole::OWNER, 'role' => UserRole::ADMIN];
    }, true],
    'owner of a suspended account with no hotels' => [function () {
        $account = dashboardAccount();
        dashboardSuspend($account);

        return ['hotel_group_id' => $account->id, 'group_role' => GroupRole::OWNER, 'role' => UserRole::ADMIN];
    }, true],
    'owner whose account hotels are all suspended, member of an active hotel elsewhere' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        dashboardSuspend($home);

        return ['hotel_id' => $home->id, 'hotel_group_id' => $account->id, 'group_role' => GroupRole::OWNER, 'role' => UserRole::ADMIN, 'member_of' => dashboardHotel()];
    }, true],
    'direct account link with no group role, home suspended, other account hotels active' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        dashboardHotel($account);
        dashboardSuspend($home);

        return ['hotel_id' => $home->id, 'hotel_group_id' => $account->id, 'role' => UserRole::ADMIN];
    }, true],
    'home hotel suspended, member of an active hotel' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        dashboardSuspend($home);

        return ['hotel_id' => $home->id, 'role' => UserRole::EMPLOYEE, 'member_of' => dashboardHotel($account)];
    }, false],
    'home hotel active, member of a suspended hotel' => [function () {
        $account = dashboardAccount();
        $member = dashboardHotel($account);
        dashboardSuspend($member);

        return ['hotel_id' => dashboardHotel($account)->id, 'role' => UserRole::EMPLOYEE, 'member_of' => $member];
    }, false],
    'only hotel soft-deleted, account suspended' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        $home->delete();
        dashboardSuspend($account);

        return ['hotel_id' => $home->id, 'role' => UserRole::EMPLOYEE];
    }, true],
    'home hotel soft-deleted, member of a suspended hotel' => [function () {
        $account = dashboardAccount();
        $home = dashboardHotel($account);
        $home->delete();
        $member = dashboardHotel($account);
        dashboardSuspend($member);

        return ['hotel_id' => $home->id, 'role' => UserRole::EMPLOYEE, 'member_of' => $member];
    }, true],
    'no hotels and no account' => [fn () => ['role' => UserRole::EMPLOYEE], false],
    'linked to a suspended account, reaches an active hotel in another account' => [function () {
        $linked = dashboardAccount();
        dashboardSuspend($linked);

        return ['hotel_id' => dashboardHotel()->id, 'hotel_group_id' => $linked->id, 'role' => UserRole::ADMIN];
    }, false],
]);

it('counts a user as suspended exactly when login refuses them', function (Closure $build, bool $suspended) {
    $attributes = $build();
    $memberOf = $attributes['member_of'] ?? null;
    unset($attributes['member_of']);

    $user = User::factory()->create($attributes);

    if ($memberOf) {
        $user->hotels()->attach($memberOf->id, ['id' => (string) Str::uuid(), 'role' => $user->role->value, 'is_primary' => false]);
    }

    // Login first: actingAs() below moves the default guard to one that can't attempt().
    $login = $this->withHeaders(apiHeaders())->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);

    expect($login->getStatusCode())->toBe($suspended ? 403 : 200);

    if ($suspended) {
        expect($login->json('body.reason'))->toBe('suspended');
    }

    expect(dashboardRead($this, 'counts')->json('body.users'))
        ->toBe(['active' => $suspended ? 0 : 1, 'suspended' => $suspended ? 1 : 0]);
})->with('kinds of user');

it('does not treat revoked tokens as a suspension', function () {
    $user = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => dashboardHotel()->id]);

    dashboardRead($this, 'counts');
    Cache::flush();

    $superAdmin = User::where('role', UserRole::SUPER_ADMIN)->first();

    $this->withHeaders(apiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson("/api/superadmin/users/{$user->id}/revoke-tokens")->assertOk();

    expect(dashboardRead($this, 'counts')->json('body.users'))->toBe(['active' => 1, 'suspended' => 0]);
});

it('runs the same number of queries for counts however many users and hotels exist', function () {
    $window = [now()->subDays(29)->startOfDay(), now()->endOfDay()];
    $summary = app(PlatformSummary::class);

    $counts = function () use ($summary, $window) {
        $summary->currentCounts();
        $summary->signups(...$window);
    };

    dashboardHotel();
    User::factory()->create();

    $before = dashboardQueries($counts);

    foreach (range(1, 5) as $i) {
        User::factory()->create(['hotel_id' => dashboardHotel()->id]);
    }

    expect(dashboardQueries($counts))->toBe($before);
});

/*
| Story 3: usage and AI cost.
*/

it('gives every usage field', function () {
    $hotel = dashboardHotel(dashboardAccount('Acme'));

    $response = dashboardRead($this, 'usage')->assertOk()
        ->assertJsonStructure(['body' => [
            'accounts' => [[
                'hotel_group_id', 'name', 'ai_cost_usd', 'cost_basis', 'estimated_rows_pct',
                'usage' => ['seats', 'features', 'recommendations'],
                'ai' => ['requests', 'by_capability', 'by_hotel'],
            ]],
            'unattributed' => ['calls', 'ai_cost_usd'],
            'from', 'to', 'cached_at',
        ]]);

    expect(array_keys($response->json('body.accounts.0')))->toBe(['hotel_group_id', 'name', 'usage', 'ai', 'ai_cost_usd', 'cost_basis', 'estimated_rows_pct'])
        ->and($response->json('body.accounts.0.name'))->toBe('Acme')
        ->and($response->json('body.accounts.0.hotel_group_id'))->toBe($hotel->hotel_group_id);
});

it('agrees with the usage and AI cost reports for the same window', function () {
    $window = ['from' => '2026-11-01', 'to' => '2026-11-08'];
    $first = dashboardHotel(dashboardAccount('First'));
    $second = dashboardHotel(dashboardAccount('Second'));

    dashboardCost($first->hotel_group_id, $first->id, 0.000421);
    dashboardCost($first->hotel_group_id, $first->id, 1.25);
    dashboardCost($second->hotel_group_id, $second->id, 3.5, estimated: true);
    app(MeteringService::class)->record($first->hotelGroup, MeterFeature::AI_MESSAGES, 7, hotel: $first);

    $dashboard = collect(dashboardRead($this, 'usage', $window)->json('body.accounts'))->keyBy('hotel_group_id');
    $query = '?'.http_build_query($window);
    $usage = collect($this->getJson("/api/superadmin/usage{$query}")->assertOk()->json('body.accounts'))->keyBy('hotel_group_id');
    $cost = collect($this->getJson("/api/superadmin/ai-cost{$query}")->assertOk()->json('body.accounts'))->keyBy('hotel_group_id');

    foreach ([$first->hotel_group_id, $second->hotel_group_id] as $id) {
        expect($dashboard[$id]['usage'])->toBe(collect($usage[$id])->only(['seats', 'features', 'recommendations'])->all())
            ->and($dashboard[$id]['ai'])->toBe($usage[$id]['ai'])
            ->and($dashboard[$id]['ai_cost_usd'])->toBe($cost[$id]['ai_cost_usd'])
            ->and($dashboard[$id]['cost_basis'])->toBe($cost[$id]['cost_basis'])
            ->and($dashboard[$id]['estimated_rows_pct'])->toBe($cost[$id]['estimated_rows_pct']);
    }

    expect($dashboard[$first->hotel_group_id]['ai_cost_usd'])->toBe(1.250421)
        ->and($dashboard[$first->hotel_group_id]['usage']['features']['ai_messages']['used'])->toBe(7);
});

it('lists an account with no activity at zero with no data', function () {
    dashboardAccount();

    $row = dashboardRead($this, 'usage')->json('body.accounts.0');

    expect((float) $row['ai_cost_usd'])->toBe(0.0)
        ->and($row['cost_basis'])->toBe('no data')
        ->and((float) $row['estimated_rows_pct'])->toBe(0.0);
});

it('says when a cost includes an estimate', function (array $estimates, string $basis, float $percent) {
    $hotel = dashboardHotel();

    foreach ($estimates as $estimated) {
        dashboardCost($hotel->hotel_group_id, $hotel->id, 0.5, $estimated);
    }

    $row = dashboardRead($this, 'usage')->json('body.accounts.0');

    expect($row['cost_basis'])->toBe($basis)
        ->and((float) $row['estimated_rows_pct'])->toBe($percent);
})->with([
    'measured' => [[false, false], 'measured', 0.0],
    'estimated' => [[true, true], 'estimated', 100.0],
    'mixed' => [[true, false, false, false], 'mixed', 25.0],
]);

it('reports calls with no account as unattributed, under no account', function () {
    $hotel = dashboardHotel();
    dashboardCost(null, null, 0.25);
    dashboardCost(null, null, 0.5);

    $body = dashboardRead($this, 'usage')->json('body');

    expect($body['unattributed'])->toMatchArray(['calls' => 2, 'ai_cost_usd' => 0.75, 'cost_basis' => 'measured'])
        ->and(collect($body['accounts'])->firstWhere('hotel_group_id', $hotel->hotel_group_id)['cost_basis'])->toBe('no data');
});

it('does not let unattributed unpriced calls read as free', function () {
    dashboardCost(null, null, 0, estimated: true);

    $unattributed = dashboardRead($this, 'usage')->json('body.unattributed');

    expect($unattributed)->toMatchArray(['calls' => 1, 'cost_basis' => 'estimated'])
        ->and((float) $unattributed['estimated_rows_pct'])->toBe(100.0);
});

it('gives zero unattributed cost when every call has an account', function () {
    expect((array) dashboardRead($this, 'usage')->json('body.unattributed'))->toMatchArray(['calls' => 0])
        ->and((float) dashboardRead($this, 'usage')->json('body.unattributed.ai_cost_usd'))->toBe(0.0);
});

it('leaves a deleted account out of the list, and keeps its cost visible as a total', function () {
    foreach ([1.25, 2.5] as $cost) {
        $account = dashboardAccount();
        dashboardCost($account->id, null, $cost, estimated: $cost === 2.5);
        $account->delete();
    }

    $live = dashboardAccount();
    dashboardCost($live->id, null, 9.0);

    $body = dashboardRead($this, 'usage')->json('body');

    expect($body['accounts'])->toHaveCount(1)
        ->and((float) $body['accounts'][0]['ai_cost_usd'])->toBe(9.0)
        ->and($body['unattributed']['calls'])->toBe(0)
        ->and($body['deleted_accounts'])->toMatchArray(['calls' => 2, 'ai_cost_usd' => 3.75, 'cost_basis' => 'mixed']);
});

it('gives zero deleted account cost when no deleted account has any', function () {
    dashboardAccount()->delete();

    expect(dashboardRead($this, 'usage')->json('body.deleted_accounts'))
        ->toMatchArray(['calls' => 0, 'cost_basis' => 'no data']);
});

it('only counts cost inside the window', function () {
    $hotel = dashboardHotel();
    dashboardCost($hotel->hotel_group_id, $hotel->id, 1.0, at: '2026-10-31 23:59:59');
    dashboardCost($hotel->hotel_group_id, $hotel->id, 2.0, at: '2026-11-01 00:00:00');

    expect((float) dashboardRead($this, 'usage', ['from' => '2026-11-01'])->json('body.accounts.0.ai_cost_usd'))->toBe(2.0);
});

it('reads a seat as measured once a recount has written it', function () {
    dashboardHotel();

    expect(dashboardRead($this, 'usage')->json('body.accounts.0.usage.seats.hotels'))
        ->toMatchArray(['used' => 1, 'measured' => true]);
});

it('reads a seat as not measured when the month of to has no count', function () {
    dashboardHotel();

    expect(dashboardRead($this, 'usage', ['from' => '2026-09-01', 'to' => '2026-09-30'])->json('body.accounts.0.usage.seats.hotels'))
        ->toMatchArray(['used' => null, 'measured' => false]);
});

it('keeps a moved hotel\'s earlier usage with the account that incurred it', function () {
    $original = dashboardAccount('Original');
    $destination = dashboardAccount('Destination');
    $hotel = dashboardHotel($original);
    dashboardCost($original->id, $hotel->id, 1.5);

    $hotel->update(['hotel_group_id' => $destination->id]);

    $response = dashboardRead($this, 'usage');
    $originalRow = dashboardRow($response, 'accounts', 'hotel_group_id', $original->id);
    $destinationRow = dashboardRow($response, 'accounts', 'hotel_group_id', $destination->id);

    expect($originalRow['ai_cost_usd'])->toBe(1.5)
        ->and($originalRow['ai']['requests'])->toBe(1)
        ->and($destinationRow['cost_basis'])->toBe('no data')
        ->and($destinationRow['ai']['requests'])->toBe(0);
});

it('keeps a deleted hotel\'s AI usage in its account', function () {
    $hotel = dashboardHotel();
    dashboardCost($hotel->hotel_group_id, $hotel->id, 1.0);
    $hotel->delete();

    $ai = dashboardRead($this, 'usage')->json('body.accounts.0.ai');

    expect($ai['requests'])->toBe(1)
        ->and($ai['by_hotel'][$hotel->id]['requests'])->toBe(1);
});

it('runs the same number of queries for usage however many accounts exist', function () {
    $window = [now()->subDays(29)->startOfDay(), now()->endOfDay()];
    $summary = app(PlatformSummary::class);

    dashboardHotel();
    $before = dashboardQueries(fn () => $summary->usage(...$window));

    foreach (range(1, 5) as $i) {
        $hotel = dashboardHotel();
        dashboardCost($hotel->hotel_group_id, $hotel->id, 0.1);
    }

    expect(dashboardQueries(fn () => $summary->usage(...$window)))->toBe($before);

    // Every account now names a hotel it no longer has, moved or deleted.
    foreach (HotelGroup::with('hotels')->get() as $i => $account) {
        foreach ($account->hotels as $hotel) {
            $i % 2 ? $hotel->delete() : $hotel->update(['hotel_group_id' => dashboardAccount()->id]);
        }
    }

    expect(dashboardQueries(fn () => $summary->usage(...$window)))->toBe($before);
});

it('still names a hotel an account no longer has', function () {
    $moved = dashboardHotel(name: 'Moved Away');
    $account = $moved->hotel_group_id;
    $deleted = dashboardHotel($moved->hotelGroup, 'Deleted Now');
    dashboardCost($account, $moved->id, 1.0);
    dashboardCost($account, $deleted->id, 1.0);

    $moved->update(['hotel_group_id' => dashboardAccount()->id]);
    $deleted->delete();

    $byHotel = dashboardRow(dashboardRead($this, 'usage'), 'accounts', 'hotel_group_id', $account)['ai']['by_hotel'];

    expect($byHotel[$moved->id]['hotel'])->toBe('Moved Away')
        ->and($byHotel[$deleted->id]['hotel'])->toBe('Deleted Now');
});

/*
| Story 7: access. The admin, employee and no-token cases are in AccessGuardTest.
*/

it('refuses an account owner on every section', function (string $section) {
    $hotel = dashboardHotel();
    $owner = User::factory()->role(UserRole::ADMIN)->create([
        'hotel_id' => $hotel->id,
        'hotel_group_id' => $hotel->hotel_group_id,
        'group_role' => GroupRole::OWNER,
    ]);

    $response = $this->withHeaders(apiHeaders())->actingAs($owner, 'sanctum')
        ->getJson("/api/superadmin/dashboard/{$section}")
        ->assertForbidden();

    expect($response->json('body'))->toBeNull();
})->with('dashboard sections');

it('refuses a hotel admin before looking at the window', function (string $section) {
    $this->withHeaders(apiHeaders())->actingAs(adminOfHotel(makeHotel()), 'sanctum')
        ->getJson("/api/superadmin/dashboard/{$section}?from=banana")
        ->assertForbidden();
})->with('dashboard sections');

it('covers the whole platform whatever X-Hotel-Id says', function () {
    $first = dashboardHotel();
    $second = dashboardHotel();
    dashboardReservation($first);
    dashboardReservation($second);

    $withHeader = dashboardRead($this, 'activity', headers: ['X-Hotel-Id' => $first->id])->assertOk();

    expect($withHeader->json('body.hotels'))->toHaveCount(2)
        ->and(dashboardRow($withHeader, 'hotels', 'hotel_id', $second->id)['reservations'])->toBe(1);

    Cache::flush();

    expect(dashboardRead($this, 'activity')->json('body.hotels'))->toEqualCanonicalizing($withHeader->json('body.hotels'));
});

it('lifts the tenant scope itself, whatever context it is called in', function () {
    $first = dashboardHotel();
    $second = dashboardHotel();
    dashboardReservation($second);
    dashboardBooking($second);

    $window = [now()->subDays(29)->startOfDay(), now()->endOfDay()];
    $summary = app(PlatformSummary::class);

    [$activity, $counts, $scope] = TenantContext::runForHotel($first->id, fn () => [
        $summary->activity(...$window),
        $summary->currentCounts(),
        TenantContext::hotelIds(),
    ]);

    $row = collect($activity)->firstWhere('hotel_id', $second->id);

    expect($activity)->toHaveCount(2)
        ->and($row['reservations'])->toBe(1)
        ->and($row['bookings'])->toBe(1)
        ->and($counts['hotels']['active'])->toBe(2)
        // and puts the caller's scope back afterwards.
        ->and($scope)->toBe([$first->id]);
});

/*
| Story 4: activity.
*/

it('counts reservations and bookings per hotel across accounts', function () {
    $first = dashboardHotel(dashboardAccount('First'), 'Alpha');
    $second = dashboardHotel(dashboardAccount('Second'), 'Beta');

    dashboardReservation($first);
    dashboardReservation($first);
    dashboardBooking($first);
    dashboardBooking($second);

    $response = dashboardRead($this, 'activity')->assertOk();

    expect($response->json('body.hotels'))->toBe([
        ['hotel_id' => $first->id, 'hotel_group_id' => $first->hotel_group_id, 'name' => 'Alpha', 'reservations' => 2, 'bookings' => 1],
        ['hotel_id' => $second->id, 'hotel_group_id' => $second->hotel_group_id, 'name' => 'Beta', 'reservations' => 0, 'bookings' => 1],
    ])->and($response->json('body'))->toHaveKeys(['from', 'to', 'cached_at']);
});

it('lists a hotel with nothing at zero, and a suspended hotel with its counts', function () {
    $quiet = dashboardHotel(name: 'Quiet');
    $stopped = dashboardHotel(name: 'Stopped');
    dashboardReservation($stopped);
    dashboardSuspend($stopped);

    $response = dashboardRead($this, 'activity');

    expect(dashboardRow($response, 'hotels', 'hotel_id', $quiet->id))->toMatchArray(['reservations' => 0, 'bookings' => 0])
        ->and(dashboardRow($response, 'hotels', 'hotel_id', $stopped->id))->toMatchArray(['reservations' => 1]);
});

it('counts only records created inside the window', function () {
    $hotel = dashboardHotel();
    dashboardCreatedAt(dashboardReservation($hotel), '2026-10-01 00:00:00');
    dashboardCreatedAt(dashboardReservation($hotel), '2026-10-31 23:59:59');
    dashboardCreatedAt(dashboardReservation($hotel), '2026-09-30 23:59:59');
    dashboardCreatedAt(dashboardReservation($hotel), '2026-11-01 00:00:00');
    dashboardCreatedAt(dashboardBooking($hotel), '2026-10-15 12:00:00');
    dashboardCreatedAt(dashboardBooking($hotel), '2026-11-02 12:00:00');

    $row = dashboardRead($this, 'activity', ['from' => '2026-10-01', 'to' => '2026-10-31'])->json('body.hotels.0');

    expect($row['reservations'])->toBe(2)
        ->and($row['bookings'])->toBe(1);
});

it('shows no revenue, price or currency in any section', function (string $section) {
    $hotel = dashboardHotel();
    dashboardReservation($hotel);
    dashboardBooking($hotel);
    dashboardCost($hotel->hotel_group_id, $hotel->id, 1.0);

    $encoded = strtolower((string) dashboardRead($this, $section)->getContent());

    foreach (['revenue', 'price', 'currency', 'amount', 'contract', 'margin'] as $word) {
        expect(str_contains($encoded, $word))->toBeFalse("{$section} mentions {$word}");
    }
})->with('dashboard sections');

it('writes no audit event when read', function (string $section) {
    $hotel = dashboardHotel();
    $before = EventLog::withoutGlobalScopes()->count();

    dashboardRead($this, $section)->assertOk();

    expect(EventLog::withoutGlobalScopes()->count())->toBe($before);
})->with('dashboard sections');

it('counts a cancelled reservation but not a soft-deleted one', function () {
    $hotel = dashboardHotel();
    dashboardReservation($hotel, ['status' => 'cancelled']);
    dashboardReservation($hotel)->delete();

    expect(dashboardRead($this, 'activity')->json('body.hotels.0.reservations'))->toBe(1);
});

it('leaves a deleted hotel out of activity', function () {
    $hotel = dashboardHotel();
    dashboardReservation($hotel);
    $hotel->delete();

    expect(dashboardRead($this, 'activity')->json('body.hotels'))->toBe([]);
});

it('shows a moved hotel under its current account', function () {
    $destination = dashboardAccount('Destination');
    $hotel = dashboardHotel();
    $hotel->update(['hotel_group_id' => $destination->id]);

    expect(dashboardRead($this, 'activity')->json('body.hotels.0.hotel_group_id'))->toBe($destination->id);
});

it('runs the same number of queries for activity however many hotels exist', function () {
    $window = [now()->subDays(29)->startOfDay(), now()->endOfDay()];
    $summary = app(PlatformSummary::class);

    dashboardHotel();
    $before = dashboardQueries(fn () => $summary->activity(...$window));

    foreach (range(1, 5) as $i) {
        dashboardReservation(dashboardHotel());
    }

    expect(dashboardQueries(fn () => $summary->activity(...$window)))->toBe($before);
});

/*
| Story 5: the window. Time is frozen at 2026-11-08.
*/

it('resolves the window', function (string $section, array $query, string $from, string $to) {
    dashboardRead($this, $section, $query)->assertOk()
        ->assertJsonPath('body.from', $from)
        ->assertJsonPath('body.to', $to);
})->with('dashboard sections')->with([
    'neither date: the 30 days ending today' => [[], '2026-10-10', '2026-11-08'],
    'from alone runs to today' => [['from' => '2026-11-01'], '2026-11-01', '2026-11-08'],
    'to alone starts 29 days earlier' => [['to' => '2026-10-31'], '2026-10-02', '2026-10-31'],
    'both' => [['from' => '2026-03-01', 'to' => '2026-03-02'], '2026-03-01', '2026-03-02'],
    'one day' => [['from' => '2026-11-08', 'to' => '2026-11-08'], '2026-11-08', '2026-11-08'],
    'exactly 12 months' => [['from' => '2025-11-08', 'to' => '2026-11-08'], '2025-11-08', '2026-11-08'],
]);

it('refuses an invalid window', function (string $section, array $query, string $field) {
    dashboardRead($this, $section, $query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with('dashboard sections')->with([
    'over 12 months' => [['from' => '2025-11-07', 'to' => '2026-11-08'], 'to'],
    'an old from sent alone' => [['from' => '2025-11-07'], 'from'],
    'to before from' => [['from' => '2026-11-02', 'to' => '2026-11-01'], 'to'],
    'empty from' => [['from' => ''], 'from'],
    'empty to' => [['to' => ''], 'to'],
    'wrong format' => [['from' => '2026-1-1'], 'from'],
    'not a real date' => [['from' => '2026-02-30'], 'from'],
    'an array' => [['from' => ['2026-01-01']], 'from'],
    'from after today' => [['from' => '2026-11-09'], 'from'],
    'to after today' => [['to' => '2026-11-09'], 'to'],
]);

it('allows 12 months from 29 February up to 28 February, not 1 March', function () {
    $this->travelTo(Carbon::parse('2029-06-01 12:00:00'));

    dashboardRead($this, 'counts', ['from' => '2028-02-29', 'to' => '2029-02-28'])->assertOk();
    dashboardRead($this, 'counts', ['from' => '2028-02-29', 'to' => '2029-03-01'])->assertUnprocessable();
});

it('keeps one window for the whole of a request that crosses midnight', function () {
    $this->travelTo(Carbon::parse('2026-11-08 23:59:59'));

    $request = SuperadminDashboardRequest::create('/');
    $from = $request->from();

    $this->travelTo(Carbon::parse('2026-11-09 00:00:01'));

    expect($from->toDateString())->toBe('2026-10-10')
        ->and($request->to()->toDateString())->toBe('2026-11-08')
        ->and($request->from()->toDateString())->toBe('2026-10-10');
});

it('counts a record at the first and last second of the window, and nothing a second outside', function () {
    $window = ['from' => '2026-10-01', 'to' => '2026-10-31'];
    $hotel = dashboardHotel();

    foreach (['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $at) {
        dashboardCreatedAt(dashboardAccount(), $at);
        dashboardCreatedAt(User::factory()->create(), $at);
        dashboardCost($hotel->hotel_group_id, $hotel->id, 1.0, at: $at);
    }

    $signups = dashboardRead($this, 'counts', $window)->json('body.signups');

    expect(array_sum(array_column($signups, 'accounts')))->toBe(2)
        ->and(array_sum(array_column($signups, 'users')))->toBe(2)
        ->and($signups[0])->toBe(['date' => '2026-10-01', 'accounts' => 1, 'users' => 1])
        ->and(end($signups))->toBe(['date' => '2026-10-31', 'accounts' => 1, 'users' => 1]);

    $row = dashboardRow(dashboardRead($this, 'usage', $window), 'accounts', 'hotel_group_id', $hotel->hotel_group_id);

    expect((float) $row['ai_cost_usd'])->toBe(2.0);
});

/*
| Story 6: the cache. The array store is told to serialize, so a value goes
| through what production's database store does to it. Without this an object
| would come back intact here and as an incomplete class there.
*/

function dashboardSerializingCache(): void
{
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
}

function dashboardHasObject(mixed $value): bool
{
    return is_object($value)
        || (is_array($value) && collect($value)->contains(fn ($item) => dashboardHasObject($item)));
}

function dashboardFill(): Hotel
{
    $hotel = dashboardHotel();
    dashboardReservation($hotel);
    dashboardBooking($hotel);
    dashboardCost($hotel->hotel_group_id, $hotel->id, 1.0);
    dashboardCost(null, null, 0.5);
    app(MeteringService::class)->record($hotel->hotelGroup, MeterFeature::AI_MESSAGES, 2, hotel: $hotel);

    return $hotel;
}

it('reuses a section for 15 minutes, then computes it again', function (string $section) {
    dashboardSerializingCache();
    $hotel = dashboardFill();

    $first = dashboardRead($this, $section)->assertOk();

    expect($first->json('body.cached_at'))->toBe('2026-11-08T12:00:00Z');

    $newHotel = dashboardHotel(name: 'Newer');
    dashboardReservation($newHotel);
    dashboardCost($hotel->hotel_group_id, $hotel->id, 5.0);
    $this->travel(899)->seconds();

    $second = dashboardRead($this, $section)->assertOk();

    expect($second->getContent())->toBe($first->getContent());

    $this->travel(2)->seconds();

    $third = dashboardRead($this, $section)->assertOk();

    expect($third->json('body.cached_at'))->toBe('2026-11-08T12:15:01Z')
        ->and($third->getContent())->not->toBe($first->getContent());
})->with('dashboard sections');

it('runs no queries on a cache hit', function (string $section) {
    dashboardFill();
    dashboardRead($this, $section)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    dashboardRead($this, $section)->assertOk();
    $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    foreach (['hotels', 'hotel_groups', 'reservations', 'bookings', 'meter_events', 'ai_usage_logs'] as $table) {
        expect(str_contains($tables, $table))->toBeFalse("a cache hit read {$table}");
    }
})->with('dashboard sections');

it('does not clear the figures when a super admin suspends an account', function () {
    dashboardSerializingCache();
    $hotel = dashboardFill();
    $first = dashboardRead($this, 'counts');

    $superAdmin = User::where('role', UserRole::SUPER_ADMIN)->first();
    $this->withHeaders(apiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson("/api/superadmin/accounts/{$hotel->hotel_group_id}/suspend", ['reason' => 'Unpaid'])
        ->assertOk();

    expect(dashboardRead($this, 'counts')->getContent())->toBe($first->getContent());
});

it('keeps a separate entry per window and per section', function () {
    dashboardSerializingCache();
    dashboardFill();

    $default = dashboardRead($this, 'counts')->json('body.cached_at');

    $this->travel(1)->minutes();

    expect(dashboardRead($this, 'activity')->json('body.cached_at'))->toBe('2026-11-08T12:01:00Z')
        ->and(dashboardRead($this, 'usage')->json('body.cached_at'))->toBe('2026-11-08T12:01:00Z')
        ->and(dashboardRead($this, 'usage', ['from' => '2026-11-01'])->json('body.cached_at'))->toBe('2026-11-08T12:01:00Z')
        ->and(dashboardRead($this, 'counts')->json('body.cached_at'))->toBe($default);
});

it('shows the same current counts in every window, after a change as before it', function () {
    dashboardSerializingCache();
    $account = dashboardAccount();

    dashboardRead($this, 'counts');
    dashboardSuspend($account);
    $this->travel(5)->minutes();

    $otherWindow = dashboardRead($this, 'counts', ['from' => '2026-11-01'])->json('body');

    // Old for up to 15 minutes, but the same answer in every date range, and
    // cached_at reports the older part rather than the signups just computed.
    expect($otherWindow['accounts'])->toBe(['active' => 1, 'suspended' => 0])
        ->and($otherWindow['cached_at'])->toBe('2026-11-08T12:00:00Z')
        ->and($otherWindow['signups'])->toHaveCount(8);

    $this->travel(11)->minutes();

    expect(dashboardRead($this, 'counts', ['from' => '2026-11-01'])->json('body.accounts'))
        ->toBe(['active' => 0, 'suspended' => 1]);
});

it('does not recount current figures for a new window', function () {
    dashboardHotel();
    dashboardRead($this, 'counts');

    DB::flushQueryLog();
    DB::enableQueryLog();
    dashboardRead($this, 'counts', ['from' => '2026-11-01']);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($queries)->not->toContain('"hotels"');
});

it('caches plain arrays under the exact key', function (string $section) {
    dashboardSerializingCache();
    dashboardFill();

    dashboardRead($this, $section, ['from' => '2026-11-01', 'to' => '2026-11-08'])->assertOk();

    $cached = Cache::get("superadmin:dashboard:v1:{$section}:2026-11-01:2026-11-08");

    expect($cached)->toBeArray()
        ->and(dashboardHasObject($cached))->toBeFalse()
        ->and($cached['cached_at'])->toBe('2026-11-08T12:00:00Z');
})->with('dashboard sections');

it('caches the current counts as plain arrays under one key', function () {
    dashboardSerializingCache();
    dashboardFill();

    dashboardRead($this, 'counts')->assertOk();

    $cached = Cache::get('superadmin:dashboard:v1:counts:current');

    expect(array_keys($cached))->toBe(['accounts', 'hotels', 'users', 'cached_at'])
        ->and(dashboardHasObject($cached))->toBeFalse();
});
