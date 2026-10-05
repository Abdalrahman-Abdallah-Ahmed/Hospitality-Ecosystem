<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use App\Support\Housekeeping\HotelOperationalDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Every hotel has Housekeeping and Maintenance teams with their categories
| (SPEC-004, D7, User Story 9, FR-040–042).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function defaultsSnapshot(Hotel $hotel): array
{
    return $hotel->fresh()->only([
        'housekeeping_team_id', 'cleaning_task_category_id', 'inspection_task_category_id',
        'maintenance_team_id', 'maintenance_task_category_id',
    ]);
}

it('gives a hotel created through the API both teams, their categories and all five settings', function () {
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();

    $id = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->postJson('/api/hotel', ['owner_id' => $owner->id, 'name' => 'Sea View', 'slug' => 'sea-view', 'currency' => 'USD'])
        ->assertCreated()
        ->assertJsonPath('body.inspection_required', false)
        ->json('body.id');

    $hotel = Hotel::find($id);
    $settings = defaultsSnapshot($hotel);

    expect(array_filter($settings))->toHaveCount(5)
        ->and(Team::find($settings['housekeeping_team_id'])->name)->toBe('Housekeeping')
        ->and(Team::find($settings['maintenance_team_id'])->name)->toBe('Maintenance')
        ->and(TaskCategory::find($settings['cleaning_task_category_id'])->team_id)->toBe($settings['housekeeping_team_id'])
        ->and(TaskCategory::find($settings['inspection_task_category_id'])->team_id)->toBe($settings['housekeeping_team_id'])
        ->and(TaskCategory::find($settings['maintenance_task_category_id'])->team_id)->toBe($settings['maintenance_team_id']);
});

it('keeps an admin choice and fills only what is missing', function () {
    $hotel = avHotel();
    $own = Team::create(['hotel_id' => $hotel->id, 'name' => 'Rooms Division', 'is_active' => true]);
    $hotel->forceFill(['housekeeping_team_id' => $own->id, 'cleaning_task_category_id' => null, 'maintenance_team_id' => null])->saveQuietly();

    HotelOperationalDefaults::ensure($hotel->fresh());

    $settings = defaultsSnapshot($hotel);
    expect($settings['housekeeping_team_id'])->toBe($own->id)
        ->and(TaskCategory::find($settings['cleaning_task_category_id'])->team_id)->toBe($own->id)
        ->and($settings['maintenance_team_id'])->toBe(Team::where('hotel_id', $hotel->id)->where('name', 'Maintenance')->value('id'));
});

it('creates nothing the second time', function () {
    $hotel = avHotel();
    $before = [Team::count(), TaskCategory::count(), defaultsSnapshot($hotel)];

    HotelOperationalDefaults::ensure($hotel->fresh());

    expect([Team::count(), TaskCategory::count(), defaultsSnapshot($hotel)])->toBe($before);
});

it('reuses an existing team of the default name instead of colliding with it', function () {
    $hotel = avHotel();
    $existing = Team::find($hotel->housekeeping_team_id);
    $hotel->forceFill(['housekeeping_team_id' => null, 'cleaning_task_category_id' => null, 'inspection_task_category_id' => null])->saveQuietly();

    HotelOperationalDefaults::ensure($hotel->fresh());

    expect($hotel->fresh()->housekeeping_team_id)->toBe($existing->id)
        ->and(Team::where('hotel_id', $hotel->id)->where('name', 'Housekeeping')->count())->toBe(1);
});

it('still routes work to a renamed default team', function () {
    [$hotel, $room, $task] = (function () {
        $hotel = avHotel();
        Team::whereKey($hotel->housekeeping_team_id)->update(['name' => 'التدبير المنزلي']);

        return hkVacatedRoomFor($this, $hotel);
    })->call($this);

    expect($task->assigned_to_team_id)->toBe($hotel->housekeeping_team_id);
});

it('checks the default settings an admin chooses', function () {
    $hotel = avHotel();
    $other = avHotel();
    $put = fn (array $payload) => hkPut($this, $hotel->owner, "/api/hotel/{$hotel->id}", $payload);

    $put(['maintenance_task_category_id' => $hotel->cleaning_task_category_id])->assertUnprocessable();
    $put(['maintenance_team_id' => $other->maintenance_team_id])->assertForbidden();

    $put(['inspection_required' => true])->assertOk()->assertJsonPath('body.inspection_required', true);
    expect($hotel->fresh()->inspection_required_since)->not->toBeNull();
});

/**
 * hkVacatedRoom() for a hotel built by the caller.
 */
function hkVacatedRoomFor($test, Hotel $hotel): array
{
    $type = avType($hotel, 'Deluxe');
    [$room] = avRooms($hotel, $type, 1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();

    return [$hotel->fresh(), $room->fresh(), Task::withoutGlobalScope('hotel')->where('room_id', $room->id)->sole()];
}
