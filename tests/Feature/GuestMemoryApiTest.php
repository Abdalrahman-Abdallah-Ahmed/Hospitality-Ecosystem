<?php

use App\Enums\Permission;
use App\Models\EventLog;
use App\Models\GuestMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * SPEC-084 FR-019–FR-021, FR-029, FR-032: staff with the right permission
 * see and delete what the AI remembers about a guest; nobody writes it by
 * hand; a deleted guest takes their memories along.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('lists a guest\'s memories, newest first, with their source stay, and filters by category', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = rapInHouseStay($hotel);
    $older = cmaMemory($guest, ['category' => 'interest', 'content' => 'Loved the camel ride.', 'extracted_at' => now()->subMonth()]);
    $newer = cmaMemory($guest, ['category' => 'preference', 'content' => 'Vegetarian.', 'language' => 'en', 'source_stay_id' => $stay->id]);

    $all = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/guest/{$guest->id}/memories")->assertOk();
    $interests = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/guest/{$guest->id}/memories?category=interest")->assertOk();

    expect(collect($all->json('body.memories.data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($all->json('body.memories.data.0'))->toMatchArray([
            'category' => 'preference',
            'content' => 'Vegetarian.',
            'language' => 'en',
            'confidence' => 'high',
        ])
        ->and($all->json('body.memories.data.0.source_stay.id'))->toBe($stay->id)
        ->and($all->json('body.memories.data.0.source_stay.arrival_date'))->not->toBeNull()
        ->and($all->json('body.guest_memories_enabled'))->toBeTrue()
        ->and(collect($interests->json('body.memories.data'))->pluck('id')->all())->toBe([$older->id]);
});

it('records each look at a guest\'s memories, without their words', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    cmaMemory($guest, ['content' => 'Allergic to shellfish.']);
    $admin = cmaAdmin($hotel);

    cmaRequest($this, $admin, 'GET', "/api/guest/{$guest->id}/memories")->assertOk();

    $event = EventLog::query()->where('event_type', 'guest.memories_viewed')->sole();

    expect($event->subject_id)->toBe($guest->id)
        ->and($event->actor_id)->toBe($admin->id)
        ->and(json_encode($event->toArray()))->not->toContain('shellfish');
});

it('deletes one memory for good, audited without its words', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $memory = cmaMemory($guest, ['content' => 'Allergic to shellfish.']);

    cmaRequest($this, cmaAdmin($hotel), 'DELETE', "/api/guest/{$guest->id}/memories/{$memory->id}")->assertOk();

    $event = EventLog::query()->where('event_type', 'guest_memory.deleted')->sole();

    expect(GuestMemory::withoutGlobalScope('hotel')->find($memory->id))->toBeNull()
        ->and($event->subject_id)->toBe($memory->id)
        ->and(json_encode($event->toArray()))->not->toContain('shellfish');
});

it('will not delete a memory through another guest', function () {
    $hotel = cmaHotel();
    $memory = cmaMemory(gsGuest($hotel));
    $other = gsGuest($hotel);

    cmaRequest($this, cmaAdmin($hotel), 'DELETE', "/api/guest/{$other->id}/memories/{$memory->id}")->assertNotFound();

    expect($memory->fresh())->not->toBeNull();
});

it('has no way to write a memory by hand', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $memory = cmaMemory($guest);
    $admin = cmaAdmin($hotel);

    cmaRequest($this, $admin, 'POST', "/api/guest/{$guest->id}/memories", ['content' => 'x'])->assertStatus(405);
    cmaRequest($this, $admin, 'PUT', "/api/guest/{$guest->id}/memories/{$memory->id}", ['content' => 'x'])->assertStatus(405);
});

it('deletes a guest\'s memories with the guest, and a restore does not bring them back', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    cmaMemory($guest);
    cmaMemory($guest, ['content' => 'Second.']);

    cmaRequest($this, cmaAdmin($hotel), 'DELETE', "/api/guest/{$guest->id}")->assertOk();

    expect(GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->count())->toBe(0);

    $guest->restore();

    expect(GuestMemory::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->count())->toBe(0);
});

it('lets an admin switch memories off for the hotel, audited, and keeps the panel working', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    cmaMemory($guest);
    $admin = cmaAdmin($hotel);

    cmaRequest($this, $admin, 'PUT', "/api/hotel/{$hotel->id}", ['guest_memories_enabled' => false])
        ->assertOk()
        ->assertJsonPath('body.guest_memories_enabled', false);

    $event = EventLog::query()->where('event_type', 'hotel.guest_memories_toggled')->sole();

    expect($event->changes)->toBe(['guest_memories_enabled' => ['from' => true, 'to' => false]])
        ->and(cmaRequest($this, $admin, 'GET', "/api/guest/{$guest->id}/memories")->assertOk()->json('body.guest_memories_enabled'))->toBeFalse();
});

it('keeps the switch from employees, whatever their role grants', function () {
    $hotel = cmaHotel();

    cmaRequest($this, cmaEmployee($hotel, Permission::cases()), 'PUT', "/api/hotel/{$hotel->id}", ['guest_memories_enabled' => false])->assertForbidden();

    expect($hotel->fresh()->guest_memories_enabled)->toBeTrue();
});

it('lets employees see or delete memories only when their role grants it', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $memory = cmaMemory($guest);

    cmaRequest($this, cmaEmployee($hotel, Permission::employeeDefaults()), 'GET', "/api/guest/{$guest->id}/memories")->assertForbidden();
    cmaRequest($this, cmaEmployee($hotel, [Permission::GUEST_MEMORIES_VIEW]), 'GET', "/api/guest/{$guest->id}/memories")->assertOk();
    cmaRequest($this, cmaEmployee($hotel, [Permission::GUEST_MEMORIES_VIEW]), 'DELETE', "/api/guest/{$guest->id}/memories/{$memory->id}")->assertForbidden();
    cmaRequest($this, cmaEmployee($hotel, [Permission::GUEST_MEMORIES_DELETE]), 'DELETE', "/api/guest/{$guest->id}/memories/{$memory->id}")->assertOk();
});
