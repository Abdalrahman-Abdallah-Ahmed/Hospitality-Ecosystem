<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\CreateReservationTool;
use App\Ai\Tools\CreateTaskTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\WhatsAppReservationCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
});

function notifiedHotel(string $name = 'Notify Hotel'): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => 'notify-'.uniqid(),
        'currency' => 'USD',
    ]);
}

function notifiedUser(Hotel $hotel, UserRole $role): User
{
    return User::factory()->role($role)->create(['hotel_id' => $hotel->id]);
}

// Task created through the API

it('emails the assignee when a task is created through the api', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $assignee = notifiedUser($hotel, UserRole::EMPLOYEE);

    $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($admin, 'sanctum')
        ->postJson('/api/task', [
            'hotel_id' => $hotel->id,
            'title' => 'Fix the AC',
            'assigned_to_user_id' => $assignee->id,
        ])
        ->assertStatus(201);

    Notification::assertSentTo(
        $assignee,
        TaskAssignedNotification::class,
        fn (TaskAssignedNotification $notification) => $notification->task->title === 'Fix the AC',
    );
    // A person typed this task in, so admins are not told about it.
    Notification::assertNotSentTo($admin, AiTaskCreatedNotification::class);
});

it('sends nothing for an unassigned task created through the api', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);

    $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($admin, 'sanctum')
        ->postJson('/api/task', ['hotel_id' => $hotel->id, 'title' => 'Fix the AC'])
        ->assertStatus(201);

    Notification::assertNothingSent();
});

// Tasks created by the AI

it('emails the assignee and the admins when the advisor creates a task', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $assignee = notifiedUser($hotel, UserRole::EMPLOYEE);

    (new CreateTaskTool($hotel, $admin))->handle(new Request([
        'title' => 'Repaint the lobby',
        'assigned_to_user_id' => $assignee->id,
    ]));

    $task = Task::sole();

    Notification::assertSentTo($assignee, TaskAssignedNotification::class, fn ($n) => $n->task->is($task));
    Notification::assertSentTo($admin, AiTaskCreatedNotification::class, fn ($n) => $n->task->is($task));
    Notification::assertNotSentTo($assignee, AiTaskCreatedNotification::class);
});

it('emails the admins when the concierge escalates to a human', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara']);

    (new EscalateToHumanTool($guest, $hotel))->handle(new Request(['reason' => 'Wants a refund']));

    Notification::assertSentTo($admin, AiTaskCreatedNotification::class);
});

it('emails the admins when the concierge files a guest service request', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara']);

    (new CreateGuestServiceRequestTool($guest, $hotel))->handle(new Request([
        'title' => 'Extra towels',
        'description' => 'Two more towels for room 203',
    ]));

    Notification::assertSentTo($admin, AiTaskCreatedNotification::class);
});

it('emails only the admins of the hotel the ai task belongs to', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $employee = notifiedUser($hotel, UserRole::EMPLOYEE);
    $otherAdmin = notifiedUser(notifiedHotel('Other Hotel'), UserRole::ADMIN);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara']);

    (new EscalateToHumanTool($guest, $hotel))->handle(new Request(['reason' => 'Wants a refund']));

    Notification::assertSentTo($admin, AiTaskCreatedNotification::class);
    Notification::assertNotSentTo($employee, AiTaskCreatedNotification::class);
    Notification::assertNotSentTo($otherAdmin, AiTaskCreatedNotification::class);
});

// Reservations created through WhatsApp

it('emails the admins when a reservation is created through whatsapp', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $employee = notifiedUser($hotel, UserRole::EMPLOYEE);
    $otherAdmin = notifiedUser(notifiedHotel('Other Hotel'), UserRole::ADMIN);

    (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => RoomType::withoutGlobalScope('hotel')->findOrFail(bookableTypeIdFor($hotel))->name]],
        'arrival_date' => '2026-10-01',
        'departure_date' => '2026-10-04',
    ]));

    $reservation = Reservation::sole();

    Notification::assertSentTo(
        $admin,
        WhatsAppReservationCreatedNotification::class,
        fn ($n) => $n->reservation->is($reservation),
    );
    Notification::assertNotSentTo($employee, WhatsAppReservationCreatedNotification::class);
    Notification::assertNotSentTo($otherAdmin, WhatsAppReservationCreatedNotification::class);
});

it('does not email admins about a reservation created through the api', function () {
    $hotel = notifiedHotel();
    $admin = notifiedUser($hotel, UserRole::ADMIN);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'phone_number' => '201222333444']);

    $this->withHeader('X-API-KEY', 'test-api-key')->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'RES-API-1',
            'arrival_date' => '2026-10-01',
            'departure_date' => '2026-10-04',
            'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
        ])
        ->assertStatus(201);

    Notification::assertNothingSent();
});

// Who counts as a hotel's admin

it('treats pivot and group admins as admins of the hotel', function () {
    $group = HotelGroup::create(['name' => 'Group', 'slug' => 'group-'.uniqid()]);
    $hotel = Hotel::create([
        'name' => 'Group Hotel',
        'slug' => 'group-hotel-'.uniqid(),
        'hotel_group_id' => $group->id,
    ]);

    $own = notifiedUser($hotel, UserRole::ADMIN);
    $pivot = User::factory()->role(UserRole::ADMIN)->create();
    $pivot->hotels()->attach($hotel->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $groupAdmin = User::factory()->role(UserRole::ADMIN)->create([
        'hotel_group_id' => $group->id,
        'group_role' => 'owner',
    ]);
    // In the group, but without group-wide access.
    User::factory()->role(UserRole::ADMIN)->create(['hotel_group_id' => $group->id]);
    notifiedUser($hotel, UserRole::EMPLOYEE);

    expect($hotel->admins()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$own->id, $pivot->id, $groupAdmin->id])->sort()->values()->all());
});

// The emails themselves

it('renders the task and reservation emails', function () {
    $hotel = notifiedHotel('Nile Hotel');
    $user = notifiedUser($hotel, UserRole::ADMIN);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '203']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'phone_number' => '201222333444']);
    $task = Task::create([
        'hotel_id' => $hotel->id,
        'room_id' => $room->id,
        'guest_id' => $guest->id,
        'title' => 'Fix the AC',
        'description' => 'It is blowing warm air.',
    ])->fresh();
    $reservation = createReservationWithRooms($hotel, [['room_id' => $room->id], []], [
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-WA-1',
        'arrival_date' => '2026-10-01',
        'departure_date' => '2026-10-04',
        'status' => 'pending',
        'adults' => 2,
        'children' => 0,
        'source' => 'whatsapp',
    ]);

    $assigned = (string) (new TaskAssignedNotification($task))->toMail($user)->render();
    $aiTask = (string) (new AiTaskCreatedNotification($task))->toMail($user)->render();
    $whatsApp = (string) (new WhatsAppReservationCreatedNotification($reservation))->toMail($user)->render();

    expect($assigned)->toContain('Fix the AC', '203', 'Sara', 'It is blowing warm air.')
        ->and($aiTask)->toContain('Nile Hotel', 'Fix the AC')
        ->and($whatsApp)->toContain('RES-WA-1', 'Nile Hotel', '201222333444', '2026-10-01', '2026-10-04', '2 × Standard', '203, unassigned');
});
