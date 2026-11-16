<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Spec 023 FR-015a: a soft-deleted hotel is out of reach through the API, by
| any route. Links and tokens stay, so a restore brings access back.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function joinHotel(User $user, Hotel $hotel): void
{
    DB::table('hotel_user')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'hotel_id' => $hotel->id, 'role' => $user->role->value]);
}

function guestIdsAs($test, User $user, array $headers = []): array
{
    return collect($test->withHeaders([...apiHeaders(), ...$headers])->actingAs($user, 'sanctum')
        ->getJson('/api/guest')->assertOk()->json('body.data'))->pluck('id')->all();
}

it('hides a deleted home hotel, with or without naming it, and restores access after', function () {
    $hotel = makeHotel();
    $admin = adminOfHotel($hotel);
    $guest = gsGuest($hotel);

    expect(guestIdsAs($this, $admin))->toBe([$guest->id]);

    $hotel->delete();
    $admin = $admin->fresh();

    expect(guestIdsAs($this, $admin))->toBeEmpty()
        ->and($admin->accessibleHotelIds())->toBe([]);

    $this->withHeaders([...apiHeaders(), 'X-Hotel-Id' => $hotel->id])->actingAs($admin, 'sanctum')
        ->getJson('/api/guest')->assertForbidden();

    $hotel->restore();

    expect(guestIdsAs($this, $admin->fresh()))->toBe([$guest->id]);
});

it('hides a deleted hotel reached through a membership', function () {
    $hotel = makeHotel();
    $member = User::factory()->role(UserRole::ADMIN)->create();
    joinHotel($member, $hotel);
    $guest = gsGuest($hotel);

    expect(guestIdsAs($this, $member))->toBe([$guest->id]);

    $hotel->delete();

    expect(guestIdsAs($this, $member->fresh()))->toBeEmpty();

    $hotel->restore();

    expect(guestIdsAs($this, $member->fresh()))->toBe([$guest->id]);
});

it('hides a deleted hotel reached through an account-wide role', function () {
    $hotel = makeHotel();
    $owner = User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $hotel->hotel_group_id, 'group_role' => 'owner']);
    $guest = gsGuest($hotel);

    expect(guestIdsAs($this, $owner))->toBe([$guest->id]);

    $hotel->delete();

    expect(guestIdsAs($this, $owner->fresh()))->toBeEmpty();
});

it('falls back to the user\'s other hotel when their home hotel is deleted', function () {
    $home = makeHotel();
    $other = makeHotel();
    $user = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $home->id]);
    joinHotel($user, $other);
    $home->delete();

    $seen = null;
    Route::middleware(['api', 'auth:sanctum', 'tenant'])
        ->get('/__test/current-hotel', function () use (&$seen) {
            $seen = TenantContext::currentHotelId();

            return response()->noContent();
        });

    $this->withHeaders(apiHeaders())->actingAs($user->fresh(), 'sanctum')->getJson('/__test/current-hotel')->assertNoContent();

    expect($seen)->toBe($other->id);
});

it('lands nothing new in a deleted hotel', function () {
    $hotel = makeHotel();
    $admin = adminOfHotel($hotel);
    $hotel->delete();

    $this->withHeaders(apiHeaders())->actingAs($admin->fresh(), 'sanctum')->postJson('/api/guest', [
        'first_name' => 'Late',
        'last_name' => 'Arrival',
        'phone_number' => '201000000009',
    ]);

    expect(Guest::withoutGlobalScopes()->where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('still lets a user with nothing left log in, seeing nothing', function () {
    $hotel = makeHotel();
    $admin = adminOfHotel($hotel);
    $admin->update(['password' => 'A-Password1!']);
    gsGuest($hotel);
    $hotel->delete();

    $token = $this->withHeaders(apiHeaders())->postJson('/api/login', [
        'email' => $admin->email,
        'password' => 'A-Password1!',
    ])->assertOk()->json('body.token');

    expect($this->withHeaders([...apiHeaders(), 'Authorization' => "Bearer {$token}"])
        ->getJson('/api/guest')->assertOk()->json('body.data'))->toBeEmpty();
});

it('keeps super admins unrestricted', function () {
    $hotel = makeHotel();
    gsGuest($hotel);
    $hotel->delete();

    expect(User::factory()->role(UserRole::SUPER_ADMIN)->create()->accessibleHotelIds())->toBeNull();
});
