<?php

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function activityApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function hotelForActivities(): Hotel
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

it('lets an employee list and view their own hotel activities for the booking form', function () {
    $hotel = hotelForActivities();
    $otherHotel = hotelForActivities();
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $own = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);
    $other = Activity::create(['hotel_id' => $otherHotel->id, 'name' => 'Sailing', 'price' => 20]);

    $list = $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/activity')
        ->assertOk();

    expect($list->json('body.data.*.id'))->toBe([$own->id]);

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/activity/{$own->id}")
        ->assertOk();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/activity/{$other->id}")
        ->assertForbidden();
});

it('does not let an employee change activities', function () {
    $hotel = hotelForActivities();
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Sailing', 'price' => 20])
        ->assertForbidden();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['name' => 'Snorkelling'])
        ->assertForbidden();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->deleteJson("/api/activity/{$activity->id}")
        ->assertForbidden();
});
