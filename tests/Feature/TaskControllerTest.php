<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function taskApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithHotelForTasks(): array
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

function staffFor(Hotel $hotel): User
{
    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
}

function taskFor(Hotel $hotel, array $overrides = []): Task
{
    return Task::create(array_merge([
        'hotel_id' => $hotel->id,
        'title' => 'Fix the AC',
    ], $overrides));
}

function teamForTask(Hotel $hotel, array $overrides = []): Team
{
    return Team::create(array_merge([
        'hotel_id' => $hotel->id,
        'name' => 'Housekeeping',
    ], $overrides));
}

function taskCategoryFor(Hotel $hotel, ?Team $team, array $overrides = []): TaskCategory
{
    return TaskCategory::create(array_merge([
        'hotel_id' => $hotel->id,
        'team_id' => $team?->id,
        'name' => 'Cleaning',
    ], $overrides));
}

// store

it('creates a task when created_by_user_id belongs to the caller own hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $staff = staffFor($hotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'created_by_user_id' => $staff->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.created_by_user_id', $staff->id);
});

it('rejects creating a task whose created_by_user_id belongs to a different hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    [, $otherHotel] = adminWithHotelForTasks();
    $stranger = staffFor($otherHotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'created_by_user_id' => $stranger->id,
        ]);

    $response->assertStatus(403);
    expect(Task::where('created_by_user_id', $stranger->id)->exists())->toBeFalse();
});

it('rejects creating a task whose assigned_to_user_id belongs to a different hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    [, $otherHotel] = adminWithHotelForTasks();
    $stranger = staffFor($otherHotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_user_id' => $stranger->id,
        ]);

    $response->assertStatus(403);
});

it('creates a task when assigned_to_user_id belongs to the caller own hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $staff = staffFor($hotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_user_id' => $staff->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.assigned_to_user_id', $staff->id);
});

it('creates a task when the task category belongs to the chosen team', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel);
    $category = taskCategoryFor($hotel, $team);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_team_id' => $team->id,
            'task_category_id' => $category->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.task_category_id', $category->id);
});

it('rejects creating a task whose task category belongs to a different team', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel, ['name' => 'Housekeeping']);
    $otherTeam = teamForTask($hotel, ['name' => 'Front Desk']);
    $category = taskCategoryFor($hotel, $otherTeam);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_team_id' => $team->id,
            'task_category_id' => $category->id,
        ]);

    $response->assertStatus(403);
    expect(Task::where('task_category_id', $category->id)->exists())->toBeFalse();
});

it('rejects creating a task whose task category is not tied to any team but a team is chosen', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel);
    $category = taskCategoryFor($hotel, null);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_team_id' => $team->id,
            'task_category_id' => $category->id,
        ]);

    $response->assertStatus(403);
});

// update

it('rejects updating a task to a created_by_user_id belonging to a different hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $task = taskFor($hotel);
    [, $otherHotel] = adminWithHotelForTasks();
    $stranger = staffFor($otherHotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['created_by_user_id' => $stranger->id]);

    $response->assertStatus(403);
    expect($task->fresh()->created_by_user_id)->not->toBe($stranger->id);
});

it('allows updating a task to a created_by_user_id belonging to the same hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $task = taskFor($hotel);
    $staff = staffFor($hotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['created_by_user_id' => $staff->id]);

    $response->assertOk()
        ->assertJsonPath('body.created_by_user_id', $staff->id);
});

it('rejects updating a task to an assigned_to_user_id belonging to a different hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $task = taskFor($hotel);
    [, $otherHotel] = adminWithHotelForTasks();
    $stranger = staffFor($otherHotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['assigned_to_user_id' => $stranger->id]);

    $response->assertStatus(403);
    expect($task->fresh()->assigned_to_user_id)->not->toBe($stranger->id);
});

it('allows updating a task to an assigned_to_user_id belonging to the same hotel', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $task = taskFor($hotel);
    $staff = staffFor($hotel);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['assigned_to_user_id' => $staff->id]);

    $response->assertOk()
        ->assertJsonPath('body.assigned_to_user_id', $staff->id);
});

it('allows updating a task category to one that belongs to the task current team', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel);
    $category = taskCategoryFor($hotel, $team);
    $task = taskFor($hotel, ['assigned_to_team_id' => $team->id]);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['task_category_id' => $category->id]);

    $response->assertOk()
        ->assertJsonPath('body.task_category_id', $category->id);
});

it('rejects updating a task category to one that does not belong to the task current team', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel, ['name' => 'Housekeeping']);
    $otherTeam = teamForTask($hotel, ['name' => 'Front Desk']);
    $category = taskCategoryFor($hotel, $otherTeam);
    $task = taskFor($hotel, ['assigned_to_team_id' => $team->id]);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['task_category_id' => $category->id]);

    $response->assertStatus(403);
    expect($task->fresh()->task_category_id)->not->toBe($category->id);
});

it('rejects reassigning a task to a team whose the existing task category does not belong to', function () {
    [$admin, $hotel] = adminWithHotelForTasks();
    $team = teamForTask($hotel, ['name' => 'Housekeeping']);
    $otherTeam = teamForTask($hotel, ['name' => 'Front Desk']);
    $category = taskCategoryFor($hotel, $team);
    $task = taskFor($hotel, ['assigned_to_team_id' => $team->id, 'task_category_id' => $category->id]);

    $response = $this->withHeaders(taskApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/task/{$task->id}", ['assigned_to_team_id' => $otherTeam->id]);

    $response->assertStatus(403);
    expect($task->fresh()->assigned_to_team_id)->toBe($team->id);
});
