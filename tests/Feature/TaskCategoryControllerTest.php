<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function taskCategoryApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithTaskCategoryHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

it('shows a task category of the admin own hotel', function () {
    [$admin, $hotel] = adminWithTaskCategoryHotel();
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'name' => 'Cleaning']);

    $this->withHeaders(taskCategoryApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/task-category/{$category->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $category->id)
        ->assertJsonPath('body.name', 'Cleaning');
});

it('does not show another hotel task category', function () {
    [$adminA] = adminWithTaskCategoryHotel();
    [, $hotelB] = adminWithTaskCategoryHotel();
    $category = TaskCategory::create(['hotel_id' => $hotelB->id, 'name' => 'Cleaning']);

    $this->withHeaders(taskCategoryApiHeaders())->actingAs($adminA, 'sanctum')
        ->getJson("/api/task-category/{$category->id}")
        ->assertForbidden();
});

it('does not show a task category to an employee', function () {
    [, $hotel] = adminWithTaskCategoryHotel();
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'name' => 'Cleaning']);

    $this->withHeaders(taskCategoryApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/task-category/{$category->id}")
        ->assertForbidden();
});
