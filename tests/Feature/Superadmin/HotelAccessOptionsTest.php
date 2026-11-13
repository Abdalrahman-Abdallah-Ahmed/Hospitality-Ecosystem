<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\StaffRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
| Spec 028: a hotel's staff roles and teams, read through /api/superadmin, so the
| user access screen can offer them without the hotel routes a super admin's own
| token is refused on.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * Two hotels in different accounts, each with two staff roles and two teams.
 *
 * @return array{0: Hotel, 1: Hotel}
 */
function haoHotels(): array
{
    return array_map(function (string $slug): Hotel {
        $account = HotelGroup::create(['name' => Str::title($slug), 'slug' => $slug]);
        $hotel = Hotel::create(['hotel_group_id' => $account->id, 'name' => Str::title($slug), 'slug' => $slug, 'currency' => 'USD']);

        foreach (['Desk', 'Night'] as $name) {
            StaffRole::create(['hotel_id' => $hotel->id, 'name' => "{$name} {$slug}", 'permissions' => [Permission::GUESTS_VIEW->value]]);
            Team::create(['hotel_id' => $hotel->id, 'name' => "{$name} {$slug}"]);
        }

        return $hotel;
    }, ['harbor', 'dunes']);
}

function haoAs($test, ?User $user = null)
{
    app('auth')->forgetGuards();

    return $test->withHeaders(apiHeaders())
        ->actingAs($user ?? User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum');
}

/**
 * @return array<string, array{0: string, 1: class-string}>
 */
function haoLists(): array
{
    return [
        'staff roles' => ['staff-roles', StaffRole::class],
        'teams' => ['teams', Team::class],
    ];
}

it('lists only the named hotel\'s records', function (string $path, string $model) {
    [$harbor, $dunes] = haoHotels();

    foreach ([$harbor, $dunes] as $hotel) {
        $ids = collect(haoAs($this)->getJson("/api/superadmin/hotels/{$hotel->id}/{$path}")->assertOk()->json('body.data'))->pluck('id')->sort()->values()->all();
        $expected = $model::withoutGlobalScopes()->where('hotel_id', $hotel->id)->pluck('id')->sort()->values()->all();

        // A new hotel also gets default teams, so the count isn't fixed.
        expect($ids)->toBe($expected)->not->toBeEmpty();
    }
})->with(haoLists());

it('supports search, sort and paging', function (string $path) {
    [$harbor] = haoHotels();

    $sorted = haoAs($this)->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}?search=harbor&sort=-name")->assertOk()->json('body.data.*.name');
    expect($sorted)->toBe(['Night harbor', 'Desk harbor']);

    $found = haoAs($this)->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}?search=Night harbor")->assertOk()->json('body.data.*.name');
    expect($found)->toBe(['Night harbor']);

    expect(haoAs($this)->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}?per_page=1")->assertOk()->json('body.data'))->toHaveCount(1);
})->with(array_map(fn (array $list) => [$list[0]], haoLists()));

it('answers 404 for an unknown or deleted hotel', function (string $path) {
    [$harbor] = haoHotels();
    $harbor->delete();

    haoAs($this)->getJson('/api/superadmin/hotels/'.Str::uuid()."/{$path}")->assertNotFound();
    haoAs($this)->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}")->assertNotFound();
})->with(array_map(fn (array $list) => [$list[0]], haoLists()));

it('refuses a hotel admin and an impersonation session', function (string $path) {
    [$harbor] = haoHotels();
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $harbor->id]);

    haoAs($this, $admin)->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}")->assertForbidden();

    $token = impersonationTokenFor($this, User::factory()->role(UserRole::SUPER_ADMIN)->create(), $admin);
    app('auth')->forgetGuards();

    $this->getJson("/api/superadmin/hotels/{$harbor->id}/{$path}", [...apiHeaders(), 'Authorization' => 'Bearer '.$token])
        ->assertForbidden();
})->with(array_map(fn (array $list) => [$list[0]], haoLists()));

it('counts a role\'s users and leaves deleted roles out', function () {
    [$harbor] = haoHotels();
    $desk = StaffRole::withoutGlobalScopes()->where('name', 'Desk harbor')->sole();
    User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $harbor->id, 'staff_role_id' => $desk->id]);
    StaffRole::withoutGlobalScopes()->where('name', 'Night harbor')->sole()->delete();

    $roles = haoAs($this)->getJson("/api/superadmin/hotels/{$harbor->id}/staff-roles")->assertOk()->json('body.data');

    expect($roles)->toHaveCount(1)
        ->and($roles[0]['name'])->toBe('Desk harbor')
        ->and($roles[0]['users_count'])->toBe(1);
});
