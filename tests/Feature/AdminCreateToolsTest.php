<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Tools\CreateActivityTool;
use App\Ai\Tools\CreateGuestTool;
use App\Ai\Tools\CreateHotelPolicyTool;
use App\Ai\Tools\CreateRoomTool;
use App\Ai\Tools\CreateTaskTool;
use App\Enums\CreatedBy;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\Priority;
use App\Enums\RoomTypes;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\Room;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function toolHotel(string $name = 'Tool Hotel'): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => 'tool-'.uniqid(),
        'currency' => 'EUR',
    ]);
}

function toolAdmin(Hotel $hotel): User
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $admin->update(['hotel_id' => $hotel->id]);

    return $admin->fresh();
}

/**
 * Call a tool the way the AI package would: a bag of arguments, nothing else.
 */
function callTool(object $tool, array $arguments): string
{
    return (string) $tool->handle(new Request($arguments));
}

it('creates a room and refuses to duplicate an existing room number', function () {
    $hotel = toolHotel();
    $tool = new CreateRoomTool($hotel);

    $result = callTool($tool, [
        'room_number' => '203',
        'room_type' => RoomTypes::SUITE->value,
        'floor' => 2,
    ]);

    $room = Room::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    expect($result)->toContain('203')
        ->and($room->room_type)->toBe(RoomTypes::SUITE)
        // `floor` is a string column ("mezzanine", "G"), not an integer.
        ->and($room->floor)->toBe('2')
        // `status` is a plain string column, not a cast enum.
        ->and($room->status)->toBe('available');

    // rooms has a unique index on (hotel_id, room_number). The tool must turn
    // that into a sentence the model can relay, not a database exception that
    // ends the conversation.
    $second = callTool($tool, ['room_number' => '203']);

    expect($second)->toContain('already exists')
        ->and(Room::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('creates an activity and drops a category belonging to another hotel', function () {
    $hotel = toolHotel('Ours');
    $other = toolHotel('Theirs');

    $theirCategory = ActivityCategory::withoutGlobalScope('hotel')->create([
        'hotel_id' => $other->id,
        'name' => 'Their Excursions',
        'slug' => 'their-excursions',
    ]);

    $result = callTool(new CreateActivityTool($hotel), [
        'name' => 'Sunset Sailing',
        'description' => 'Two hours along the coast.',
        'price' => 85.0,
        'category_id' => $theirCategory->id,
    ]);

    $activity = Activity::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    // The activity is still created — but uncategorised, not filed under
    // another property's taxonomy. A model can produce any uuid it has seen.
    expect($result)->toContain('Sunset Sailing')
        ->and($activity->category_id)->toBeNull()
        ->and((float) $activity->price)->toBe(85.0)
        ->and($activity->currency)->toBe('EUR')   // falls back to the hotel's own
        ->and($activity->is_active)->toBeTrue();
});

it('creates a task, resolving the room by number and recording who asked', function () {
    $hotel = toolHotel();
    $admin = toolAdmin($hotel);

    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '512', 'room_type' => RoomTypes::DOUBLE->value]);
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Maintenance']);
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'name' => 'Repairs']);

    $result = callTool(new CreateTaskTool($hotel, $admin), [
        'title' => 'Fix the air conditioning',
        'description' => 'Guest reports it is blowing warm.',
        'priority' => Priority::HIGH->value,
        'room_number' => '512',
        'task_category_id' => $category->id,
        'assigned_to_team_id' => $team->id,
    ]);

    $task = Task::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    expect($result)->toContain('Fix the air conditioning')
        ->and($task->room_id)->toBe($room->id)
        ->and($task->task_category_id)->toBe($category->id)
        ->and($task->assigned_to_team_id)->toBe($team->id)
        ->and($task->priority)->toBe(Priority::HIGH)
        ->and($task->status)->toBe(TaskStatus::PENDING)
        // The row was written by the agent; the admin who asked for it is kept
        // separately, so "what wrote this" and "who wanted it" stay distinct.
        ->and($task->created_by)->toBe(CreatedBy::AI)
        ->and($task->created_by_user_id)->toBe($admin->id);
});

it('never assigns a task to another hotel\'s team or staff', function () {
    $hotel = toolHotel('Ours');
    $admin = toolAdmin($hotel);

    $other = toolHotel('Theirs');
    $theirTeam = Team::create(['hotel_id' => $other->id, 'name' => 'Their Crew']);
    $theirUser = User::factory()->role(UserRole::EMPLOYEE)->create();
    $theirUser->update(['hotel_id' => $other->id]);

    callTool(new CreateTaskTool($hotel, $admin), [
        'title' => 'Cross-tenant attempt',
        'assigned_to_team_id' => $theirTeam->id,
        'assigned_to_user_id' => $theirUser->id,
        'room_number' => '999',
    ]);

    $task = Task::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    // Dropped, not honoured. Assigning one hotel's task to another hotel's
    // team would put a staff member's work list in front of the wrong
    // property; an unassigned task is visible and fixable instead.
    expect($task)->not->toBeNull()
        ->and($task->assigned_to_team_id)->toBeNull()
        ->and($task->assigned_to_user_id)->toBeNull()
        ->and($task->room_id)->toBeNull();
});

it('records a guest and returns the existing one instead of duplicating', function () {
    $hotel = toolHotel();
    $tool = new CreateGuestTool($hotel);

    $first = callTool($tool, [
        'phone_number' => '+201234567890',
        'first_name' => 'Ada',
        'last_name' => 'Byron',
        'nationality' => 'GB',
    ]);

    expect($first)->toContain('Ada Byron');

    $guest = Guest::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    // A duplicate guest splits their stays, bookings and history in two, and
    // nothing downstream reports an error — it just quietly knows less.
    $second = callTool($tool, [
        'phone_number' => '+201234567890',
        'first_name' => 'Ada',
        'nationality' => 'FR',
    ]);

    expect($second)->toContain('already exists')
        ->and(Guest::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count())->toBe(1)
        // And the existing record was left exactly as it was.
        ->and($guest->fresh()->nationality)->toBe('GB');
});

it('requires a phone number before creating a guest', function () {
    $hotel = toolHotel();

    $result = callTool(new CreateGuestTool($hotel), ['first_name' => 'Nameless']);

    expect($result)->toContain('phone number is required')
        ->and(Guest::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('records a policy and sends it for embedding', function () {
    Queue::fake();

    $hotel = toolHotel();

    $result = callTool(new CreateHotelPolicyTool($hotel), [
        'title' => 'Cancellation policy',
        'content' => 'Free cancellation up to 48 hours before arrival.',
        'category' => KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value,
        'keywords' => ['cancel', 'refund', ''],
    ]);

    $policy = HotelPolicy::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->first();

    expect($result)->toContain('Cancellation policy')
        ->and($policy->content)->toContain('48 hours')
        ->and($policy->is_active)->toBeTrue()
        // Blank keywords are dropped rather than stored as empty strings.
        ->and($policy->keywords)->toBe(['cancel', 'refund']);

    // An active policy is grounding data the concierge quotes to guests, so it
    // gets embedded — which is real provider spend, metered and costed.
    Queue::assertPushed(SyncKnowledgeChunksJob::class);
});

it('refuses to write a policy with no content', function () {
    $hotel = toolHotel();

    $result = callTool(new CreateHotelPolicyTool($hotel), ['title' => 'Empty policy']);

    expect($result)->toContain('required')
        ->and(HotelPolicy::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('gives the admin advisor its create tools, each bound to the admin\'s own hotel', function () {
    $hotel = toolHotel();
    $admin = toolAdmin($hotel);

    $tools = collect(AdminAdvisorAgent::make(user: $admin)->tools())
        ->map(fn ($tool) => class_basename($tool))
        ->all();

    expect($tools)->toContain(
        'CreateReservationTool',
        'CreateRoomTool',
        'CreateActivityTool',
        'CreateTaskTool',
        'CreateGuestTool',
        'CreateHotelPolicyTool',
    );
});
