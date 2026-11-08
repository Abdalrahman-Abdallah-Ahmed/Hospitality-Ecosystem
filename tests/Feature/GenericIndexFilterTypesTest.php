<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| A filter value the column can't hold is the caller's 422, not a query that
| fails on Postgres with a 500.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function hotelListAs($test, string $query)
{
    return $test->withHeaders(apiHeaders())
        ->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum')
        ->getJson('/api/hotel?'.$query);
}

it('refuses a filter value the column cannot hold', function (string $query, string $error) {
    hotelListAs($this, $query)->assertStatus(422)->assertJsonValidationErrors([$error]);
})->with([
    'a malformed uuid' => ['filter[hotel_group_id]=abc', 'filter.hotel_group_id'],
    'a malformed uuid in a list' => ['filter[hotel_group_id][]=abc', 'filter.hotel_group_id'],
    'a nested list' => ['filter[hotel_group_id][0][]=abc', 'filter.hotel_group_id'],
    'a word for a boolean' => ['filter[is_active]=maybe', 'filter.is_active'],
    'a word for a date' => ['filter[created_at]=abc', 'filter.created_at'],
    'a date that does not exist' => ['filter[created_at]=2026-02-31', 'filter.created_at'],
    'a relative date' => ['filter[created_at]=tomorrow', 'filter.created_at'],
    'a json column' => ['filter[branding]=abc', 'filter.branding'],
]);

it('accepts filter values the column can hold', function (Closure $query) {
    hotelListAs($this, $query(makeHotel()))->assertOk();
})->with([
    'a uuid' => [fn ($hotel) => "filter[hotel_group_id]={$hotel->hotel_group_id}"],
    'a list of uuids' => [fn ($hotel) => "filter[hotel_group_id][]={$hotel->hotel_group_id}"],
    'a boolean' => [fn () => 'filter[is_active]=true'],
    'a boolean as a number' => [fn () => 'filter[is_active]=1'],
    'a date' => [fn () => 'filter[created_at]=2026-02-28'],
    'a timestamp' => [fn () => 'filter[created_at]=2026-02-28T10:00:00Z'],
    'text' => [fn () => 'filter[city]=abc'],
    'an empty value, meaning no filter' => [fn () => 'filter[hotel_group_id]='],
]);

it('answers a filter that is not a list without failing', function () {
    hotelListAs($this, 'filter=abc')->assertStatus(422);
});
