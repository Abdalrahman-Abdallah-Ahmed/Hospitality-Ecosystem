<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Jobs\CreateAiInsightsJob;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\Room;
use App\Models\StaffRole;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\VoucherEmailRequest;
use App\Models\WhatsAppDevice;
use App\Services\Knowledge\ArticleCommands;
use App\Services\SenderRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;

/*
| Spec 025: admins work in the hotels they can reach, one hotel per request,
| and only the hotel list and the hotel's own record reach further.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

// User Story 1: the hotel's own record

it('opens every hotel of the account to its owner', function (string $method) {
    [$account, $a, $b] = spAccount();
    $owner = spUser($a, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);

    spRequest($this, $owner, $method, "/api/hotel/{$b->id}", ['city' => 'Cairo'])
        ->assertOk()
        ->assertJsonPath('body.id', $b->id);
})->with(['GET', 'PUT', 'PATCH']);

it('opens a hotel to any admin based there, not only one of them', function (string $method) {
    [, $a] = spAccount();
    spUser($a);
    $second = spUser($a);

    spRequest($this, $second, $method, "/api/hotel/{$a->id}", ['city' => 'Cairo'])->assertOk();
})->with(['GET', 'PUT', 'PATCH']);

it('refuses an admin a hotel of their account they are not assigned to', function (string $method) {
    [, $a, $b] = spAccount();
    $admin = spUser($a);

    spRequest($this, $admin, $method, "/api/hotel/{$b->id}", ['city' => 'Cairo'])->assertForbidden();

    expect($b->fresh()->city)->toBeNull();
})->with(['GET', 'PUT', 'PATCH']);

it('opens a hotel to an admin who is a member of it', function (string $method) {
    [, $a, $b] = spAccount();
    $admin = spUser($a, [$b]);

    spRequest($this, $admin, $method, "/api/hotel/{$b->id}", ['city' => 'Cairo'])->assertOk();
})->with(['GET', 'PUT', 'PATCH']);

it('refuses an employee their own hotel\'s record, whatever their permissions', function (string $method) {
    [, $a] = spAccount();
    $role = StaffRole::create([
        'hotel_id' => $a->id,
        'name' => 'Everything',
        'permissions' => array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
    ]);
    $employee = spUser($a, role: UserRole::EMPLOYEE, attributes: ['staff_role_id' => $role->id]);

    spRequest($this, $employee, $method, "/api/hotel/{$a->id}", ['city' => 'Cairo'])->assertForbidden();

    expect($a->fresh()->city)->toBeNull();
})->with(['GET', 'PUT', 'PATCH']);

it('tells a stranger nothing about a suspended hotel', function (string $method) {
    [, $a] = spAccount();
    [, $own] = spAccount('other');
    spSuspend($a);

    $response = spRequest($this, spUser($own), $method, "/api/hotel/{$a->id}", ['city' => 'Cairo'])->assertForbidden();

    expect($response->json('body.reason'))->toBeNull()
        ->and($response->json('body.scope'))->toBeNull();
})->with(['GET', 'PUT', 'PATCH']);

it('gives an owner the suspended answer on a suspended hotel, and writes nothing', function (string $method) {
    [$account, $a, $b] = spAccount();
    $owner = spUser($a, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);
    spSuspend($b);

    spRequest($this, $owner, $method, "/api/hotel/{$b->id}", ['city' => 'Cairo'])
        ->assertForbidden()
        ->assertJsonPath('body.reason', 'suspended')
        ->assertJsonPath('body.scope', 'hotel');

    expect($b->fresh()->city)->toBeNull();
})->with(['GET', 'PUT', 'PATCH']);

it('leaves a super admin unaffected, suspended hotel or not', function () {
    [, $a, $b] = spAccount();
    spSuspend($b);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    spRequest($this, $superAdmin, 'GET', "/api/hotel/{$a->id}")->assertOk();
    spRequest($this, $superAdmin, 'GET', "/api/hotel/{$b->id}")->assertOk();
});

// User Story 8: one hotel per request, in every module

function mhTeam(Hotel $hotel, string $name = 'Night shift'): Team
{
    return Team::create(['hotel_id' => $hotel->id, 'name' => $name, 'is_active' => true]);
}

/**
 * An account with three hotels, an owner based at A, and an admin based at A who
 * is also a member of B.
 *
 * @return array{0: Hotel, 1: Hotel, 2: Hotel, 3: User, 4: User}
 */
function mhAccount(): array
{
    [$account, $a, $b] = spAccount();
    $c = Hotel::create(['hotel_group_id' => $account->id, 'name' => 'acme c', 'slug' => 'acme-c', 'currency' => 'USD']);
    $owner = spUser($a, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);
    $admin = spUser($a, [$b]);

    return [$a, $b, $c, $owner, $admin];
}

function mhChoose(Hotel $hotel): array
{
    return ['X-Hotel-Id' => $hotel->id];
}

function mhEveryPermission(Hotel $hotel): StaffRole
{
    return StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Everything',
        'permissions' => array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
    ]);
}

it('works in the chosen hotel, and otherwise in the default one', function () {
    [$a, $b, , $owner] = mhAccount();
    $teamA = mhTeam($a, 'In A');
    $teamB = mhTeam($b, 'In B');

    $chosen = spRequest($this, $owner, 'GET', '/api/team', headers: mhChoose($b))->assertOk();
    $default = spRequest($this, $owner, 'GET', '/api/team')->assertOk();

    expect(collect($chosen->json('body.data'))->pluck('id'))->toContain($teamB->id)->not->toContain($teamA->id)
        ->and(collect($default->json('body.data'))->pluck('id'))->toContain($teamA->id)->not->toContain($teamB->id);

    spRequest($this, $owner, 'GET', "/api/team/{$teamB->id}", headers: mhChoose($b))->assertOk();
    spRequest($this, $owner, 'PUT', "/api/team/{$teamB->id}", ['name' => 'Renamed'], mhChoose($b))->assertOk();
    spRequest($this, $owner, 'GET', "/api/team/{$teamB->id}")->assertNotFound();

    expect($owner->fresh()->hotel_id)->toBe($a->id);
});

it('creates a record in the hotel the request works in, whatever the body says', function () {
    [$a, $b, , $owner] = mhAccount();

    $chosen = spRequest($this, $owner, 'POST', '/api/team', ['name' => 'Chosen', 'hotel_id' => $a->id], mhChoose($b))->assertCreated();
    $default = spRequest($this, $owner, 'POST', '/api/team', ['name' => 'Default', 'hotel_id' => $b->id])->assertCreated();

    expect(Team::withoutGlobalScope('hotel')->find($chosen->json('body.id'))->hotel_id)->toBe($b->id)
        ->and(Team::withoutGlobalScope('hotel')->find($default->json('body.id'))->hotel_id)->toBe($a->id);
});

it('shows only the hotel the request works in on the dashboard, cached apart', function () {
    [$a, $b, , $owner] = mhAccount();

    foreach ([$a, $a, $b] as $index => $hotel) {
        Task::create(['hotel_id' => $hotel->id, 'title' => "Task {$index}", 'created_by' => 'manual', 'status' => 'pending', 'priority' => 'normal']);
    }

    spRequest($this, $owner, 'GET', '/api/dashboard')->assertOk()->assertJsonPath('body.pending_tasks', 2);
    spRequest($this, $owner, 'GET', '/api/dashboard', headers: mhChoose($b))->assertOk()->assertJsonPath('body.pending_tasks', 1);
    spRequest($this, $owner, 'GET', '/api/dashboard')->assertOk()->assertJsonPath('body.pending_tasks', 2);
});

it('files a voucher email request under the chosen hotel', function () {
    Queue::fake();
    [, $b, , $owner] = mhAccount();
    $reservation = vcReservation($b);

    spRequest($this, $owner, 'POST', "/api/reservation/{$reservation->id}/voucher/email", [
        'request_key' => (string) Str::uuid(), 'email' => 'agent@travel.test',
    ], mhChoose($b))->assertStatus(202);

    expect(VoucherEmailRequest::withoutGlobalScope('hotel')->sole()->hotel_id)->toBe($b->id);
});

it('lets an admin work in their assigned hotels and refuses the others', function () {
    [$a, $b, $c, , $admin] = mhAccount();
    $teamB = mhTeam($b);
    $teamC = mhTeam($c);

    spRequest($this, $admin, 'GET', '/api/team', headers: mhChoose($a))->assertOk();
    spRequest($this, $admin, 'GET', "/api/team/{$teamB->id}", headers: mhChoose($b))->assertOk();
    spRequest($this, $admin, 'GET', "/api/team/{$teamC->id}")->assertNotFound();
    spRequest($this, $admin, 'GET', "/api/team/{$teamC->id}", headers: mhChoose($b))->assertNotFound();
    spRequest($this, $admin, 'GET', "/api/team/{$teamC->id}", headers: mhChoose($c))->assertForbidden();
});

it('refuses a link to another hotel\'s record', function () {
    [$a, $b, , $owner] = mhAccount();
    $typeA = roomTypeIdFor($a);

    spRequest($this, $owner, 'POST', '/api/room', ['room_type_id' => $typeA, 'room_number' => '101', 'hotel_id' => $b->id], mhChoose($b))
        ->assertForbidden();

    expect(Room::withoutGlobalScope('hotel')->where('hotel_id', $b->id)->count())->toBe(0);
});

it('keeps listing every hotel, with one chosen', function () {
    [$a, $b, $c, $owner, $admin] = mhAccount();

    $ids = fn ($response) => collect($response->assertOk()->json('body.data'))->pluck('id')->all();

    expect($ids(spRequest($this, $owner, 'GET', '/api/hotel', headers: mhChoose($a))))->toEqualCanonicalizing([$a->id, $b->id, $c->id])
        ->and($ids(spRequest($this, $admin, 'GET', '/api/hotel', headers: mhChoose($b))))->toEqualCanonicalizing([$a->id, $b->id]);
});

it('checks a hotel\'s own team settings against that hotel, with another chosen', function () {
    [$a, $b, , $owner] = mhAccount();
    $teamId = $b->fresh()->housekeeping_team_id;

    expect($teamId)->not->toBeNull();

    spRequest($this, $owner, 'PUT', "/api/hotel/{$b->id}", ['housekeeping_team_id' => $teamId], mhChoose($a))->assertOk();
    spRequest($this, $owner, 'PUT', "/api/hotel/{$b->id}", ['housekeeping_team_id' => $a->fresh()->housekeeping_team_id], mhChoose($a))->assertForbidden();
});

it('manages the staff of the chosen hotel', function () {
    [$a, $b, , $owner] = mhAccount();
    $staffA = spUser($a, role: UserRole::EMPLOYEE);
    $staffB = spUser($b, role: UserRole::EMPLOYEE);

    $listed = collect(spRequest($this, $owner, 'GET', '/api/users', headers: mhChoose($b))->assertOk()->json('body.data'))->pluck('id');

    expect($listed)->toContain($staffB->id)->not->toContain($staffA->id);

    spRequest($this, $owner, 'PUT', "/api/users/{$staffB->id}", ['name' => 'Renamed'], mhChoose($b))->assertOk();
    spRequest($this, $owner, 'PUT', "/api/users/{$staffB->id}", ['name' => 'Again'])->assertForbidden();

    $created = spRequest($this, $owner, 'POST', '/api/users', [
        'name' => 'New', 'email' => 'new@example.com', 'password' => 'Password123!',
    ], mhChoose($b))->assertCreated();

    expect(User::find($created->json('body.id'))->hotel_id)->toBe($b->id);

    $role = spRequest($this, $owner, 'POST', '/api/staff-roles', ['name' => 'Desk', 'permissions' => []], mhChoose($b))->assertCreated();

    expect(StaffRole::withoutGlobalScope('hotel')->find($role->json('body.id'))->hotel_id)->toBe($b->id);
});

it('keeps an admin\'s own team on their profile, with another hotel chosen', function () {
    [$a, $b, , $owner] = mhAccount();
    $team = mhTeam($a);
    $owner->update(['team_id' => $team->id]);

    spRequest($this, $owner, 'GET', '/api/user', headers: mhChoose($b))
        ->assertOk()
        ->assertJsonPath('body.team.id', $team->id)
        ->assertJsonPath('body.hotel.id', $b->id)
        ->assertJsonPath('body.hotel_id', $a->id);
});

it('leaves an employee on their home hotel, member hotels included', function () {
    [$a, $b] = mhAccount();
    $employee = spUser($a, [$b], UserRole::EMPLOYEE, ['staff_role_id' => mhEveryPermission($a)->id]);
    $teamA = mhTeam($a, 'In A');
    $teamB = mhTeam($b, 'In B');

    spRequest($this, $employee, 'GET', "/api/team/{$teamA->id}")->assertOk();
    spRequest($this, $employee, 'GET', "/api/team/{$teamB->id}")->assertForbidden();
    spRequest($this, $employee, 'GET', "/api/team/{$teamB->id}", headers: mhChoose($b))->assertForbidden();
});

it('does not find a suspended hotel\'s record, and refuses choosing that hotel', function () {
    [, $b, , $owner] = mhAccount();
    $teamB = mhTeam($b);
    spSuspend($b);

    spRequest($this, $owner, 'GET', "/api/team/{$teamB->id}")->assertNotFound();
    spRequest($this, $owner, 'GET', '/api/team', headers: mhChoose($b))
        ->assertForbidden()
        ->assertJsonPath('body.reason', 'suspended');
});

// X-Hotel-Id naming a hotel out of reach

it('refuses a header naming a hotel out of reach, the same way every time', function (Closure $header, UserRole $role) {
    [$account, $a, $b] = spAccount();
    [, $elsewhere] = spAccount('other');
    $gone = Hotel::create(['hotel_group_id' => $account->id, 'name' => 'gone', 'slug' => 'gone', 'currency' => 'USD']);
    $attributes = $role === UserRole::ADMIN ? [] : ['staff_role_id' => mhEveryPermission($a)->id];
    $user = spUser($a, [$gone], $role, $attributes);
    $gone->delete();
    $headers = ['X-Hotel-Id' => $header($b, $elsewhere, $gone)];
    $plain = ['message' => 'This action is unauthorized.', 'code' => 403, 'body' => null];

    spRequest($this, $user, 'GET', '/api/team', headers: $headers)->assertForbidden()->assertExactJson($plain);
    spRequest($this, $user, 'POST', '/api/team', ['name' => 'Refused'], $headers)->assertForbidden()->assertExactJson($plain);

    expect(Team::withoutGlobalScope('hotel')->where('name', 'Refused')->exists())->toBeFalse();
})->with([
    'another hotel of the account' => [fn ($b) => $b->id],
    'another account\'s hotel' => [fn ($b, $elsewhere) => $elsewhere->id],
    'a deleted hotel' => [fn ($b, $elsewhere, $gone) => $gone->id],
    'an unknown id' => [fn () => (string) Str::uuid()],
    'a malformed id' => [fn () => 'not-a-uuid'],
])->with([
    'admin' => UserRole::ADMIN,
    'employee' => UserRole::EMPLOYEE,
]);

it('leaves a super admin free to send any header', function () {
    [, $a] = spAccount();
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    spRequest($this, $superAdmin, 'GET', '/api/team', headers: mhChoose($a))->assertOk();
    spRequest($this, $superAdmin, 'GET', '/api/team', headers: ['X-Hotel-Id' => (string) Str::uuid()])->assertOk();
});

it('still lets a user with a stale header log out, list hotels and use their device', function () {
    [, , $c, , $admin] = mhAccount();
    $stale = mhChoose($c);

    spRequest($this, $admin, 'GET', '/api/hotel', headers: $stale)->assertOk();
    spRequest($this, $admin, 'POST', '/api/connect', headers: $stale)->assertOk();
    spRequest($this, $admin, 'GET', '/api/check-paired?phone_number=201000000000', headers: $stale)->assertSuccessful();
    spRequest($this, $admin, 'POST', '/api/logout', headers: $stale)->assertOk();
});

it('still finds a device of the home hotel, with another hotel chosen', function () {
    [$a, $b, , $owner] = mhAccount();

    WhatsAppDevice::create([
        'user_id' => $owner->id,
        'phone_number' => '201000000000',
        'hotel_id' => $a->id,
        'wa_user_id' => 'wa-1',
        'status' => 'active',
    ]);

    spRequest($this, $owner, 'GET', '/api/check-paired?phone_number=201000000000', headers: mhChoose($b))
        ->assertOk()
        ->assertJsonPath('body.paired', true);
});

it('gives an owner another hotel when their home hotel is deleted', function () {
    [$account, $a, $b] = spAccount();
    $owner = spUser($a, attributes: ['hotel_group_id' => $account->id, 'group_role' => 'owner']);
    $a->delete();

    spRequest($this, $owner, 'GET', '/api/dashboard')->assertOk();
    $team = spRequest($this, $owner, 'POST', '/api/team', ['name' => 'Fallback', 'hotel_id' => $a->id])->assertCreated();

    expect(Team::withoutGlobalScope('hotel')->find($team->json('body.id'))->hotel_id)->toBe($b->id);
});

// Nothing lands in the wrong hotel

it('leaves an edited article in its own hotel', function () {
    [, $b, , $owner] = mhAccount();
    $article = KnowledgeBaseArticle::create(['hotel_id' => $b->id, 'title' => 'In B', 'content' => 'Text.', 'status' => 'draft']);

    spRequest($this, $owner, 'PUT', "/api/knowledge-base-articles/{$article->id}", ['title' => 'Renamed'], mhChoose($b))->assertOk();

    expect(KnowledgeBaseArticle::withoutGlobalScope('hotel')->find($article->id)->hotel_id)->toBe($b->id);

    app(ArticleCommands::class)->update($article, ['title' => 'Again']);

    expect(KnowledgeBaseArticle::withoutGlobalScope('hotel')->find($article->id)->hotel_id)->toBe($b->id);
});

it('keeps an advisor conversation, and any action waiting in it, in the hotel it started in', function () {
    Queue::fake();
    $seed = aatSeed();
    $hotel = $seed['hotel'];
    $admin = $seed['admin'];
    $other = Hotel::create(['hotel_group_id' => $hotel->hotel_group_id, 'name' => 'Other', 'slug' => 'other', 'currency' => 'USD']);
    $admin->update(['hotel_group_id' => $hotel->hotel_group_id, 'group_role' => 'owner']);

    AdminAdvisorAgent::fake([new ToolCall('call_1', 'CancelReservationTool', ['code' => $seed['code']]), 'Done.']);

    $paused = spRequest($this, $admin, 'POST', '/api/ai-advisor/chat', ['message' => 'Cancel '.$seed['code']])->assertOk();
    $conversation = $paused->json('body.conversation_id');

    expect($paused->json('body.pending_confirmation.ids'))->toBe(['call_1'])
        ->and(Conversation::find($conversation)->getAttribute('hotel_id'))->toBe($hotel->id);

    spRequest($this, $admin, 'POST', '/api/ai-advisor/chat', ['conversation_id' => $conversation, 'decision' => 'confirm'], mhChoose($other))
        ->assertNotFound()
        ->assertJsonPath('message', 'Conversation not found.');

    spRequest($this, $admin, 'POST', '/api/ai-advisor/chat', ['conversation_id' => $conversation, 'message' => 'yes'], mhChoose($other))
        ->assertNotFound();

    AdminAdvisorAgent::assertNotPrompted(fn ($prompt) => $prompt->approvalDecisions?->get('*') !== null);

    spRequest($this, $admin, 'POST', '/api/ai-advisor/chat', ['conversation_id' => $conversation, 'decision' => 'decline'], mhChoose($hotel))
        ->assertOk();
});

it('runs an insights job for the chosen hotel', function () {
    Queue::fake();
    [, $b, , $owner] = mhAccount();

    spRequest($this, $owner, 'POST', '/api/ai-insights', headers: mhChoose($b))->assertCreated();

    Queue::assertPushed(CreateAiInsightsJob::class, fn (CreateAiInsightsJob $job) => $job->hotelId() === $b->id);
});

it('keeps a WhatsApp sender on their default hotel, whatever they chose on the web', function () {
    [$a, $b, , $owner] = mhAccount();
    $owner->update(['phone_number' => '201000000111']);

    spRequest($this, $owner, 'GET', '/api/user', headers: mhChoose($b))->assertOk();

    expect(app(SenderRecognitionService::class)->resolve('201000000111')->hotelId)->toBe($a->id);
});
