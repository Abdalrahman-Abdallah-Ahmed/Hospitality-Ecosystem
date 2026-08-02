<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function teamApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithHotelForTeams(): array
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

function teamFor(Hotel $hotel, array $overrides = []): Team
{
    return Team::create(array_merge([
        'hotel_id' => $hotel->id,
        'name' => 'Housekeeping',
    ], $overrides));
}

function staffForTeams(Hotel $hotel): User
{
    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
}

// store

it('creates a team scoped to the caller own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    [, $otherHotel] = adminWithHotelForTeams();

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/team', [
            'hotel_id' => $otherHotel->id,
            'name' => 'Front Desk',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);

    expect(Team::where('hotel_id', $otherHotel->id)->exists())->toBeFalse();
});

it('allows two different hotels to have a team with the same name', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    [$otherAdmin, $otherHotel] = adminWithHotelForTeams();
    teamFor($hotel, ['name' => 'Front Desk']);

    $response = $this->withHeaders(teamApiHeaders())->actingAs($otherAdmin, 'sanctum')
        ->postJson('/api/team', ['hotel_id' => $otherHotel->id, 'name' => 'Front Desk']);

    $response->assertStatus(201);
    expect(Team::where('hotel_id', $otherHotel->id)->where('name', 'Front Desk')->exists())->toBeTrue();
});

it('rejects creating a team with a name already used within the same hotel', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    teamFor($hotel, ['name' => 'Front Desk']);

    $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/team', ['hotel_id' => $hotel->id, 'name' => 'Front Desk'])
        ->assertStatus(422);
});

// update

it('lets an admin update a team belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/team/{$team->id}", ['name' => 'Maintenance']);

    $response->assertOk()->assertJsonPath('body.name', 'Maintenance');
});

it('rejects an admin updating a team belonging to a different hotel', function () {
    [, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);

    [$stranger] = adminWithHotelForTeams();

    $this->withHeaders(teamApiHeaders())->actingAs($stranger, 'sanctum')
        ->putJson("/api/team/{$team->id}", ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect($team->fresh()->name)->not->toBe('Hijacked');
});

it('ignores an attempt to reassign a team to a different hotel on update', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);
    [, $otherHotel] = adminWithHotelForTeams();

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/team/{$team->id}", ['hotel_id' => $otherHotel->id]);

    $response->assertOk();
    expect($team->fresh()->hotel_id)->toBe($hotel->id);
});

// members

it('adds a member belonging to the same hotel to the team', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);
    $staff = staffForTeams($hotel);

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/team/{$team->id}/members", ['user_id' => $staff->id]);

    $response->assertOk();
    expect($staff->fresh()->team_id)->toBe($team->id);
});

it('rejects adding a member who belongs to a different hotel', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);
    [, $otherHotel] = adminWithHotelForTeams();
    $stranger = staffForTeams($otherHotel);

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/team/{$team->id}/members", ['user_id' => $stranger->id]);

    $response->assertStatus(403);
    expect($stranger->fresh()->team_id)->not->toBe($team->id);
});

it('rejects an admin from adding a member to a team belonging to a different hotel', function () {
    [, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);
    $staff = staffForTeams($hotel);

    [$stranger] = adminWithHotelForTeams();

    $this->withHeaders(teamApiHeaders())->actingAs($stranger, 'sanctum')
        ->postJson("/api/team/{$team->id}/members", ['user_id' => $staff->id])
        ->assertStatus(403);

    expect($staff->fresh()->team_id)->not->toBe($team->id);
});

it('rejects adding a member whose role is not employee', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);

    $response = $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/team/{$team->id}/members", ['user_id' => $admin->id]);

    $response->assertStatus(422);
    expect($admin->fresh()->team_id)->not->toBe($team->id);
});

it('requires a user_id to add a member to a team', function () {
    [$admin, $hotel] = adminWithHotelForTeams();
    $team = teamFor($hotel);

    $this->withHeaders(teamApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/team/{$team->id}/members", [])
        ->assertStatus(422);
});
