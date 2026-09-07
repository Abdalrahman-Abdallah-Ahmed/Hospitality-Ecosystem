<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function makeGroupTestHotel(array $overrides = []): Hotel
{
    return Hotel::create(array_merge([
        'name' => 'Coral Bay Resort',
        'slug' => 'coral-bay-'.uniqid(),
        'currency' => 'EGP',
        'timezone' => 'Africa/Cairo',
        'country_code' => 'EG',
    ], $overrides));
}

it('gives a hotel created without a group a single-property group of its own', function () {
    $hotel = makeGroupTestHotel();

    expect($hotel->hotel_group_id)->not->toBeNull();
    expect($hotel->hotelGroup)->not->toBeNull()
        ->and($hotel->hotelGroup->hotels)->toHaveCount(1);
});

it('mirrors the hotel onto the group it creates', function () {
    $group = makeGroupTestHotel()->hotelGroup;

    expect($group->name)->toBe('Coral Bay Resort')
        ->and($group->country_code)->toBe('EG')
        ->and($group->default_currency)->toBe('EGP')
        ->and($group->default_timezone)->toBe('Africa/Cairo');
});

it('keeps the group it was given instead of creating another', function () {
    $group = HotelGroup::create(['name' => 'Domina', 'slug' => 'domina-'.uniqid()]);

    $hotel = makeGroupTestHotel(['hotel_group_id' => $group->id]);

    expect($hotel->hotel_group_id)->toBe($group->id)
        ->and(HotelGroup::count())->toBe(1);
});

it('puts every hotel in a group whichever entry point created it', function () {
    // Registration is the path that mattered: it created a hotel directly and
    // never set a group, so every account in the system had none.
    $response = $this->withHeaders(['X-API-KEY' => 'test-api-key'])
        ->postJson('/api/register', [
            'name' => 'Owner',
            'email' => 'owner-'.uniqid().'@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'hotel' => ['name' => 'Registered Hotel', 'city' => 'Sharm'],
        ]);

    $response->assertCreated();

    expect(Hotel::whereNull('hotel_group_id')->count())->toBe(0)
        ->and(Hotel::latest('created_at')->first()->hotelGroup)->not->toBeNull();
});

it('does not reuse a slug that a soft-deleted group still holds', function () {
    $slug = 'shared-slug-'.uniqid();

    HotelGroup::create(['name' => 'Original', 'slug' => $slug])->delete();

    $group = makeGroupTestHotel(['slug' => $slug])->hotelGroup;

    expect($group->slug)->not->toBe($slug)
        ->and($group->slug)->toStartWith($slug);
});

it('requires a hotel group at the database level', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();

    expect(fn () => DB::table('hotels')->insert([
        'id' => (string) Str::uuid(),
        'owner_id' => $owner->id,
        'hotel_group_id' => null,
        'name' => 'Accountless',
        'slug' => 'accountless-'.uniqid(),
        'currency' => 'USD',
        'timezone' => 'UTC',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to hard delete a group that still has hotels', function () {
    $group = makeGroupTestHotel()->hotelGroup;

    expect(fn () => $group->forceDelete())
        ->toThrow(QueryException::class);
});
