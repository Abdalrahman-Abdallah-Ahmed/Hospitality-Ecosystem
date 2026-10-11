<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('lists a hotel\'s guest conversations, newest activity first, with counts and times', function () {
    $hotel = cmaHotel();
    $older = gsGuest($hotel, ['first_name' => 'Older']);
    $newer = gsGuest($hotel, ['first_name' => 'Newer']);

    $olderId = cmaGuestConversation($older, [
        ['role' => 'user', 'content' => 'Hi', 'at' => now()->subDays(3)],
        ['role' => 'assistant', 'content' => 'Hello!', 'at' => now()->subDays(2)],
    ]);
    $newerId = cmaGuestConversation($newer, [
        ['role' => 'user', 'content' => 'Is breakfast included?', 'at' => now()->subHours(2)],
        ['role' => 'assistant', 'content' => 'Yes, it is.', 'at' => now()->subHour()],
        ['role' => 'user', 'content' => 'Thanks', 'at' => now()->subMinutes(5)],
    ]);

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', '/api/conversations')->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$newerId, $olderId])
        ->and($response->json('body.data.0.guest.id'))->toBe($newer->id)
        ->and($response->json('body.data.0.guest.name'))->toBe('Newer Haddad')
        ->and($response->json('body.data.0.guest.phone'))->toBe($newer->phone_number)
        ->and($response->json('body.data.0.message_count'))->toBe(3)
        ->and($response->json('body.data.0.started_at'))->not->toBeNull()
        ->and($response->json('body.data.0.last_message_at'))->not->toBeNull()
        ->and($response->json('body.meta.per_page'))->toBe(25);
});

it('filters by guest and by last-activity date, and ignores a search term', function () {
    $hotel = cmaHotel();
    $lina = gsGuest($hotel);
    $omar = gsGuest($hotel, ['first_name' => 'Omar']);
    $linaId = cmaGuestConversation($lina, [['role' => 'user', 'content' => 'Hi', 'at' => now()->subDays(10)]]);
    $omarId = cmaGuestConversation($omar, [['role' => 'user', 'content' => 'Hi', 'at' => now()->subHour()]]);
    $admin = cmaAdmin($hotel);

    $byGuest = cmaRequest($this, $admin, 'GET', "/api/conversations?guest_id={$lina->id}")->assertOk();
    $recent = cmaRequest($this, $admin, 'GET', '/api/conversations?last_active_from='.now($hotel->timezone)->subDay()->toDateString())->assertOk();
    $older = cmaRequest($this, $admin, 'GET', '/api/conversations?last_active_to='.now($hotel->timezone)->subDays(5)->toDateString())->assertOk();
    $search = cmaRequest($this, $admin, 'GET', '/api/conversations?search=Omar')->assertOk();

    expect(collect($byGuest->json('body.data'))->pluck('id')->all())->toBe([$linaId])
        ->and(collect($recent->json('body.data'))->pluck('id')->all())->toBe([$omarId])
        ->and(collect($older->json('body.data'))->pluck('id')->all())->toBe([$linaId])
        ->and($search->json('body.data'))->toHaveCount(2);
});

it('shows one conversation in order, flags proactive messages, and hides tool data', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $id = cmaGuestConversation($guest, [
        ['role' => 'user', 'content' => 'Is breakfast included?', 'at' => now()->subHours(3), 'attachments' => [['type' => 'image', 'name' => 'receipt.jpg', 'base64' => 'AAAA']]],
        ['role' => 'assistant', 'content' => '', 'at' => now()->subHours(3)->addSecond(), 'tool_calls' => [['name' => 'KnowledgeSearchTool']]],
        ['role' => 'tool', 'content' => '{"result": "secret tool payload"}', 'at' => now()->subHours(3)->addSeconds(2)],
        ['role' => 'assistant', 'content' => 'Yes, breakfast is included.', 'at' => now()->subHours(3)->addSeconds(3)],
        ['role' => 'assistant', 'content' => 'Reminder: your safari is tomorrow at 16:00.', 'at' => now()->subHour(), 'proactive' => true],
    ]);

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/conversations/{$id}")->assertOk();
    $messages = $response->json('body.messages.data');

    expect($response->json('body.conversation.id'))->toBe($id)
        ->and($response->json('body.conversation.guest.id'))->toBe($guest->id)
        ->and($messages)->toHaveCount(3)
        ->and(array_column($messages, 'sender'))->toBe(['guest', 'concierge', 'concierge'])
        ->and(array_column($messages, 'text'))->toBe(['Is breakfast included?', 'Yes, breakfast is included.', 'Reminder: your safari is tomorrow at 16:00.'])
        ->and(array_column($messages, 'proactive'))->toBe([false, false, true])
        ->and($messages[0]['attachments'])->toBe([['type' => 'image', 'name' => 'receipt.jpg']])
        ->and($response->json('body.messages.meta.per_page'))->toBe(50);

    $raw = $response->getContent();
    expect($raw)->not->toContain('secret tool payload')
        ->not->toContain('tool_calls')
        ->not->toContain('tool_results')
        ->not->toContain('"usage"')
        ->not->toContain('"meta":{"proactive"')
        ->not->toContain('AAAA');
});

it('pages long conversations', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $messages = collect(range(1, 60))->map(fn (int $i) => ['role' => 'user', 'content' => "Message {$i}", 'at' => now()->subMinutes(100 - $i)])->all();
    $id = cmaGuestConversation($guest, $messages);

    $page2 = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/conversations/{$id}?page=2")->assertOk();

    expect($page2->json('body.messages.data'))->toHaveCount(10)
        ->and($page2->json('body.messages.data.0.text'))->toBe('Message 51')
        ->and($page2->json('body.messages.meta.total'))->toBe(60);
});

it('never shows staff conversations or a deleted guest\'s conversations', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);
    $staffId = cmaStaffConversation($admin);
    $deleted = gsGuest($hotel);
    $deletedId = cmaGuestConversation($deleted, [['role' => 'user', 'content' => 'Hi']]);
    $deleted->delete();

    expect(cmaRequest($this, $admin, 'GET', '/api/conversations')->assertOk()->json('body.data'))->toBe([]);

    cmaRequest($this, $admin, 'GET', "/api/conversations/{$staffId}")->assertNotFound();
    cmaRequest($this, $admin, 'GET', "/api/conversations/{$deletedId}")->assertNotFound();
    cmaRequest($this, $admin, 'GET', '/api/conversations/'.Str::uuid7())->assertNotFound();
});

it('makes a super admin name the hotel', function () {
    $hotel = cmaHotel();
    $id = cmaGuestConversation(gsGuest($hotel), [['role' => 'user', 'content' => 'Hi']]);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    cmaRequest($this, $superAdmin, 'GET', '/api/conversations')->assertUnprocessable();
    cmaRequest($this, $superAdmin, 'GET', "/api/conversations/{$id}")->assertUnprocessable();

    expect(cmaRequest($this, $superAdmin, 'GET', "/api/conversations?hotel_id={$hotel->id}")->assertOk()->json('body.data.0.id'))->toBe($id);
    cmaRequest($this, $superAdmin, 'GET', "/api/conversations/{$id}?hotel_id={$hotel->id}")->assertOk();
});

it('lets an employee in only when their role grants conversations.view', function () {
    $hotel = cmaHotel();
    $id = cmaGuestConversation(gsGuest($hotel), [['role' => 'user', 'content' => 'Hi']]);
    $without = cmaEmployee($hotel, Permission::employeeDefaults());
    $with = cmaEmployee($hotel, [Permission::CONVERSATIONS_VIEW]);

    cmaRequest($this, $without, 'GET', '/api/conversations')->assertForbidden();
    cmaRequest($this, $without, 'GET', "/api/conversations/{$id}")->assertForbidden();
    cmaRequest($this, $with, 'GET', '/api/conversations')->assertOk();
    cmaRequest($this, $with, 'GET', "/api/conversations/{$id}")->assertOk();
});

it('records opening a conversation, once, without its text, and not the list', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $admin = cmaAdmin($hotel);
    $id = cmaGuestConversation($guest, [['role' => 'user', 'content' => 'My secret wish']]);

    cmaRequest($this, $admin, 'GET', '/api/conversations')->assertOk();
    cmaRequest($this, $admin, 'GET', "/api/conversations/{$id}")->assertOk();
    cmaRequest($this, $admin, 'GET', "/api/conversations/{$id}?page=2")->assertOk();

    $events = EventLog::query()->where('event_type', 'guest.conversation_viewed')->get();

    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_id)->toBe($guest->id)
        ->and($events[0]->actor_id)->toBe($admin->id)
        ->and($events[0]->context['conversation_id'])->toBe($id)
        ->and(json_encode($events[0]->toArray()))->not->toContain('My secret wish')
        ->and(EventLog::query()->where('event_type', 'like', '%conversation%')->count())->toBe(1);
});

it('has no way to change a conversation', function () {
    $hotel = cmaHotel();
    $id = cmaGuestConversation(gsGuest($hotel), [['role' => 'user', 'content' => 'Hi']]);
    $admin = cmaAdmin($hotel);

    cmaRequest($this, $admin, 'POST', '/api/conversations', ['content' => 'x'])->assertStatus(405);
    cmaRequest($this, $admin, 'PUT', "/api/conversations/{$id}", ['content' => 'x'])->assertStatus(405);
    cmaRequest($this, $admin, 'DELETE', "/api/conversations/{$id}")->assertStatus(405);
});
