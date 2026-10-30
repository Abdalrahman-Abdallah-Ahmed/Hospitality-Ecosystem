<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\Task;
use App\Models\User;
use App\Services\BookingCancellationService;
use App\Services\Vouchers\VoucherTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    // The ledger is off by default (spec 019); these tests exercise it.
    config(['features.transactions' => true]);
});

function permissionApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function hotelForPermissions(): Hotel
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

/**
 * An employee of the hotel. With no permissions given they have no staff role
 * and so get the defaults; otherwise they hold a role granting exactly those.
 *
 * @param  list<Permission>|null  $permissions
 */
function employeeWithPermissions(Hotel $hotel, ?array $permissions = null): User
{
    $role = $permissions === null ? null : StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create([
        'hotel_id' => $hotel->id,
        'staff_role_id' => $role?->id,
    ]);
}

function guestForPermissions(Hotel $hotel): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);
}

/**
 * A valid availability lookup a month out, so a run that crosses midnight
 * cannot turn its arrival date into "the past".
 */
function availabilityProbeUrl(): string
{
    return '/api/availability?'.http_build_query([
        'arrival_date' => now()->addDays(30)->toDateString(),
        'departure_date' => now()->addDays(31)->toDateString(),
    ]);
}

it('lets employees without a role read availability but never overbook by default', function () {
    expect(Permission::employeeDefaults())
        ->toContain(Permission::AVAILABILITY_VIEW)
        ->not->toContain(Permission::RESERVATIONS_OVERBOOK);
});

it('gives an employee without a staff role exactly the default permissions', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('body.staff_role', null)
        ->assertJsonPath('body.permissions', array_map(
            fn (Permission $permission) => $permission->value,
            Permission::employeeDefaults(),
        ));

    foreach (['/api/guest', '/api/activity', '/api/booking', '/api/dashboard', availabilityProbeUrl()] as $allowed) {
        $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
            ->getJson($allowed)->assertOk();
    }

    foreach (['/api/room', '/api/task', '/api/reservation', '/api/transaction', '/api/stays'] as $denied) {
        $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
            ->getJson($denied)->assertForbidden();
    }
});

it('reports every permission for an admin', function () {
    $hotel = hotelForPermissions();
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(permissionApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonCount(count(Permission::cases()), 'body.permissions');
});

it('lets an employee read a resource only when their role grants it', function (string $endpoint, Permission $permission) {
    $hotel = hotelForPermissions();
    $granted = employeeWithPermissions($hotel, [$permission]);
    $withoutIt = employeeWithPermissions($hotel, []);

    $this->withHeaders(permissionApiHeaders())->actingAs($granted, 'sanctum')
        ->getJson($endpoint)->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($withoutIt, 'sanctum')
        ->getJson($endpoint)->assertForbidden();
})->with([
    'activities' => ['/api/activity', Permission::ACTIVITIES_VIEW],
    'activity categories' => ['/api/activity-category', Permission::ACTIVITY_CATEGORIES_VIEW],
    'ai activity' => ['/api/ai-activity', Permission::HISTORY_VIEW],
    'availability' => [availabilityProbeUrl(), Permission::AVAILABILITY_VIEW],
    'ai insights' => ['/api/ai-insights', Permission::AI_INSIGHTS_VIEW],
    'bookings' => ['/api/booking', Permission::BOOKINGS_VIEW],
    'conversations' => ['/api/conversations', Permission::CONVERSATIONS_VIEW],
    'booking cancellation requests' => ['/api/booking/cancellation-requests', Permission::BOOKINGS_VIEW],
    'dashboard' => ['/api/dashboard', Permission::DASHBOARD_VIEW],
    'guests' => ['/api/guest', Permission::GUESTS_VIEW],
    'hotel policies' => ['/api/hotel-policy', Permission::HOTEL_POLICIES_VIEW],
    'knowledge base articles' => ['/api/knowledge-base-articles', Permission::KNOWLEDGE_BASE_ARTICLES_VIEW],
    'recommendations' => ['/api/recommendation', Permission::RECOMMENDATIONS_VIEW],
    'reservations' => ['/api/reservation', Permission::RESERVATIONS_VIEW],
    'reservation imports' => ['/api/reservation/imports', Permission::RESERVATIONS_IMPORT],
    'rooms' => ['/api/room', Permission::ROOMS_VIEW],
    'room types' => ['/api/room-types', Permission::ROOM_TYPES_VIEW],
    'stays' => ['/api/stays', Permission::STAYS_VIEW],
    'arrivals' => ['/api/stays/arrivals', Permission::STAYS_VIEW],
    'departures' => ['/api/stays/departures', Permission::STAYS_VIEW],
    'in-house' => ['/api/stays/in-house', Permission::STAYS_VIEW],
    'task categories' => ['/api/task-category', Permission::TASK_CATEGORIES_VIEW],
    'tasks' => ['/api/task', Permission::TASKS_VIEW],
    'teams' => ['/api/team', Permission::TEAMS_VIEW],
    'transactions' => ['/api/transaction', Permission::TRANSACTIONS_VIEW],
    'housekeeping board' => ['/api/housekeeping/board', Permission::ROOMS_VIEW],
    'maintenance list' => ['/api/maintenance/tasks', Permission::TASKS_VIEW],
    'voucher templates' => ['/api/voucher-templates', Permission::VOUCHER_TEMPLATES_VIEW],
    'voucher fields' => ['/api/voucher-templates/fields', Permission::VOUCHER_TEMPLATES_VIEW],
]);

it('never gives employees without a role the housekeeping or out-of-order permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS)
        ->not->toContain(Permission::ROOMS_SET_OUT_OF_ORDER);
});

it('lets an employee run housekeeping and maintenance actions only when their role grants it', function (string $action, Permission $permission) {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    hkPut($this, $hotel->owner, "/api/hotel/{$hotel->id}", ['inspection_required' => true])->assertOk();
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $inspection = Task::where('housekeeping_kind', 'inspection')->sole();

    $call = fn ($user) => match ($action) {
        'housekeeping status' => hkPut($this, $user, "/api/room/{$room->id}/housekeeping-status", ['housekeeping_status' => 'dirty', 'reason' => 'Spill']),
        'take out of order' => fdPost($this, $user, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak']),
        'edit out of order' => hkPatch($this, $user, "/api/room/{$room->id}/out-of-order", ['reason' => 'Bigger leak']),
        'return to service' => fdPost($this, $user, "/api/room/{$room->id}/return-to-service"),
        'inspection' => fdPost($this, $user, "/api/task/{$inspection->id}/inspection", ['result' => 'pass']),
        'report issue' => fdPost($this, $user, "/api/task/{$cleaning->id}/issues", ['description' => 'Leak']),
    };

    if (in_array($action, ['edit out of order', 'return to service'], true)) {
        fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
    }

    $call(fdEmployee($hotel, Permission::employeeDefaults()))->assertForbidden();
    expect($call(fdEmployee($hotel, [$permission]))->status())->toBeIn([200, 201]);
})->with([
    'housekeeping status' => ['housekeeping status', Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS],
    'take out of order' => ['take out of order', Permission::ROOMS_SET_OUT_OF_ORDER],
    'edit out of order' => ['edit out of order', Permission::ROOMS_SET_OUT_OF_ORDER],
    'return to service' => ['return to service', Permission::ROOMS_SET_OUT_OF_ORDER],
    'inspection' => ['inspection', Permission::TASKS_UPDATE],
    'report issue' => ['report issue', Permission::TASKS_UPDATE],
]);

it('never gives employees without a role the stays permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::STAYS_VIEW)
        ->not->toContain(Permission::STAYS_CHECK_IN)
        ->not->toContain(Permission::STAYS_CHECK_OUT);
});

it('lets an employee check guests in and out only when their role grants it', function (string $action, string $scope) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    if ($action === 'check-out') {
        fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    }

    $permission = $action === 'check-in' ? Permission::STAYS_CHECK_IN : Permission::STAYS_CHECK_OUT;
    $uri = $scope === 'room' ? "/api/stays/{$stay->id}/{$action}" : "/api/reservation/{$reservation->id}/{$action}";

    fdPost($this, fdEmployee($hotel, Permission::employeeDefaults()), $uri)->assertForbidden();
    fdPost($this, fdEmployee($hotel, [$permission]), $uri)->assertOk();
})->with([
    'check-in a room' => ['check-in', 'room'],
    'check-in a reservation' => ['check-in', 'reservation'],
    'check-out a room' => ['check-out', 'room'],
    'check-out a reservation' => ['check-out', 'reservation'],
]);

it('never gives employees without a role the no-show permission', function () {
    expect(Permission::employeeDefaults())->not->toContain(Permission::RESERVATIONS_NO_SHOW);
});

it('lets an employee mark and undo no-shows only when their role grants it', function (string $action, string $scope) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $base = $scope === 'room' ? "/api/stays/{$stay->id}" : "/api/reservation/{$reservation->id}";

    if ($action === 'undo-no-show') {
        fdPost($this, $hotel->owner, "{$base}/no-show")->assertOk();
    }

    fdPost($this, fdEmployee($hotel, [Permission::RESERVATIONS_UPDATE, Permission::STAYS_CHECK_IN]), "{$base}/{$action}")->assertForbidden();
    fdPost($this, fdEmployee($hotel, [Permission::RESERVATIONS_NO_SHOW]), "{$base}/{$action}")->assertOk();
})->with([
    'mark a reservation' => ['no-show', 'reservation'],
    'mark a room' => ['no-show', 'room'],
    'undo a reservation' => ['undo-no-show', 'reservation'],
    'undo a room' => ['undo-no-show', 'room'],
]);

it('never gives employees without a role the voucher permissions', function () {
    $voucher = [
        Permission::RESERVATIONS_VOUCHER, Permission::RESERVATIONS_VOUCHER_EMAIL,
        Permission::VOUCHER_TEMPLATES_VIEW, Permission::VOUCHER_TEMPLATES_CREATE,
        Permission::VOUCHER_TEMPLATES_UPDATE, Permission::VOUCHER_TEMPLATES_DELETE,
    ];

    foreach ($voucher as $permission) {
        expect(Permission::employeeDefaults())->not->toContain($permission);
    }

    $listed = $this->withHeaders(permissionApiHeaders())->actingAs(hotelForPermissions()->owner, 'sanctum')
        ->getJson('/api/permissions')->assertOk()->json();

    foreach ($voucher as $permission) {
        expect(json_encode($listed))->toContain($permission->value);
    }
});

it('lets an employee generate, email and manage vouchers only when their role grants it', function (string $action, Permission $permission) {
    Queue::fake();
    Storage::fake(config('vouchers.disk'));
    $hotel = hotelForPermissions();
    $reservation = vcReservation($hotel);
    $template = app(VoucherTemplateService::class)->create($hotel, ['name' => 'Main', 'language' => 'en'], vcImage(1240, 1754), null);

    $call = fn (User $user) => match ($action) {
        'generate' => vcPost($this, $user, "/api/reservation/{$reservation->id}/voucher"),
        'email' => vcPost($this, $user, "/api/reservation/{$reservation->id}/voucher/email", ['request_key' => (string) Str::uuid()]),
        'email history' => vcGet($this, $user, "/api/reservation/{$reservation->id}/voucher/emails"),
        'upload' => $this->withHeaders([...permissionApiHeaders(), 'Accept' => 'application/json'])->actingAs($user, 'sanctum')
            ->post('/api/voucher-templates', ['name' => 'T '.Str::random(4), 'language' => 'en', 'image' => vcImage(1240, 1754)]),
        'rename' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->putJson("/api/voucher-templates/{$template->id}", ['name' => 'Renamed '.Str::random(4)]),
        'delete' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->deleteJson("/api/voucher-templates/{$template->id}"),
        'show' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->getJson("/api/voucher-templates/{$template->id}"),
        'image' => $this->withHeaders([...permissionApiHeaders(), 'Accept' => 'application/json'])->actingAs($user, 'sanctum')->get("/api/voucher-templates/{$template->id}/image"),
        'preview' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->postJson("/api/voucher-templates/{$template->id}/preview"),
        'layout' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->putJson("/api/voucher-templates/{$template->id}/layout", ['layout' => []]),
        'replace image' => $this->withHeaders([...permissionApiHeaders(), 'Accept' => 'application/json'])->actingAs($user, 'sanctum')
            ->post("/api/voucher-templates/{$template->id}/image", ['image' => vcImage(1240, 1754)]),
    };

    $call(employeeWithPermissions($hotel, Permission::employeeDefaults()))->assertForbidden();
    // getStatusCode(): the image endpoint streams, and a streamed response has no status().
    expect($call(employeeWithPermissions($hotel, [$permission]))->getStatusCode())->toBeIn([200, 201, 202]);
})->with([
    'generate' => ['generate', Permission::RESERVATIONS_VOUCHER],
    'email' => ['email', Permission::RESERVATIONS_VOUCHER_EMAIL],
    'email history' => ['email history', Permission::RESERVATIONS_VOUCHER_EMAIL],
    'upload a template' => ['upload', Permission::VOUCHER_TEMPLATES_CREATE],
    'rename a template' => ['rename', Permission::VOUCHER_TEMPLATES_UPDATE],
    'delete a template' => ['delete', Permission::VOUCHER_TEMPLATES_DELETE],
    'show a template' => ['show', Permission::VOUCHER_TEMPLATES_VIEW],
    'download a template image' => ['image', Permission::VOUCHER_TEMPLATES_VIEW],
    'preview a template' => ['preview', Permission::VOUCHER_TEMPLATES_VIEW],
    'save a layout' => ['layout', Permission::VOUCHER_TEMPLATES_UPDATE],
    'replace a template image' => ['replace image', Permission::VOUCHER_TEMPLATES_UPDATE],
]);

it('replaces the defaults with the role instead of adding to them', function () {
    $hotel = hotelForPermissions();
    $housekeeper = employeeWithPermissions($hotel, [Permission::ROOMS_VIEW]);

    $this->withHeaders(permissionApiHeaders())->actingAs($housekeeper, 'sanctum')
        ->getJson('/api/room')->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($housekeeper, 'sanctum')
        ->getJson('/api/guest')->assertForbidden();
});

it('lets an employee change and delete guests only when their role grants it', function () {
    $hotel = hotelForPermissions();
    $manager = employeeWithPermissions($hotel, [Permission::GUESTS_UPDATE, Permission::GUESTS_DELETE]);
    $reader = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW]);
    $guest = guestForPermissions($hotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($reader, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['first_name' => 'Renamed'])->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($reader, 'sanctum')
        ->deleteJson("/api/guest/{$guest->id}")->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($manager, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['first_name' => 'Renamed'])->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($manager, 'sanctum')
        ->deleteJson("/api/guest/{$guest->id}")->assertOk();

    expect(Guest::withTrashed()->find($guest->id))
        ->first_name->toBe('Renamed')
        ->trashed()->toBeTrue();
});

it('does not let a granted permission reach another hotel', function () {
    $hotel = hotelForPermissions();
    $otherHotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW, Permission::GUESTS_UPDATE, Permission::GUESTS_DELETE]);
    $otherGuest = guestForPermissions($otherHotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/guest/{$otherGuest->id}")->assertNotFound();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/guest/{$otherGuest->id}", ['first_name' => 'Hijacked'])->assertNotFound();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->deleteJson("/api/guest/{$otherGuest->id}")->assertNotFound();

    $listed = $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/guest')->assertOk();

    expect(collect($listed->json('body.data'))->pluck('id'))->not->toContain($otherGuest->id)
        ->and($otherGuest->fresh())->first_name->not->toBe('Hijacked')
        ->trashed()->toBeFalse();
});

it('keeps admin-only areas closed to an employee whose role grants everything', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, Permission::cases());

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/users')->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/users/{$employee->id}", ['role' => UserRole::ADMIN->value])->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Hello'])->assertForbidden();

    expect($employee->fresh()->isEmployee())->toBeTrue();
});

it('grants nothing when the assigned role can no longer be read', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW]);
    $employee->staffRole->delete();

    // Guests are in the defaults too, so a 403 proves there is no fallback.
    $this->withHeaders(permissionApiHeaders())->actingAs($employee->fresh(), 'sanctum')
        ->getJson('/api/guest')->assertForbidden();
});

it('never gives employees without a role any knowledge document permission, and lists them for the role editor', function () {
    $documentPermissions = array_filter(Permission::cases(), fn (Permission $p) => $p->group() === 'knowledge_documents');

    expect($documentPermissions)->toHaveCount(5);
    foreach ($documentPermissions as $permission) {
        expect(Permission::employeeDefaults())->not->toContain($permission);
    }

    $hotel = hotelForPermissions();
    $listed = collect($this->withHeaders(permissionApiHeaders())->actingAs(User::find($hotel->owner_id), 'sanctum')
        ->getJson('/api/permissions')->assertOk()->json('body'))->flatten()->all();

    foreach ($documentPermissions as $permission) {
        expect($listed)->toContain($permission->value);
    }
});

it('lets an employee work on knowledge documents only when their role grants it', function (string $action, Permission $permission) {
    Storage::fake('local');
    Queue::fake();
    knFakeEmbeddings();
    $hotel = avHotel();
    $document = knDocument($hotel, ['title' => 'House Rules']);
    Storage::disk('local')->put($document->path, 'stored file');
    $deleted = knDocument($hotel, ['title' => 'Old menu']);
    $deleted->delete();

    $call = fn ($user) => match ($action) {
        'list' => knRequest($this, $user, 'GET', '/api/knowledge-documents'),
        'show' => knRequest($this, $user, 'GET', "/api/knowledge-documents/{$document->id}"),
        'download' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->get("/api/knowledge-documents/{$document->id}/download", ['Accept' => 'application/json']),
        'view text' => knRequest($this, $user, 'GET', "/api/knowledge-documents/{$document->id}/text"),
        'upload' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')
            ->post('/api/knowledge-documents', ['file' => knUpload('plain.txt'), 'title' => 'Notes'], ['Accept' => 'application/json']),
        'edit' => knRequest($this, $user, 'PUT', "/api/knowledge-documents/{$document->id}", ['title' => 'Renamed']),
        'replace file' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')
            ->post("/api/knowledge-documents/{$document->id}/file", ['file' => knUpload('notes.md')], ['Accept' => 'application/json']),
        'correct text' => knRequest($this, $user, 'PUT', "/api/knowledge-documents/{$document->id}/text", ['segments' => [['location' => 'Page 1', 'text' => 'Checkout is at 12:00.']]]),
        'discard corrections' => knRequest($this, $user, 'DELETE', "/api/knowledge-documents/{$document->id}/text"),
        'reindex' => knRequest($this, $user, 'POST', "/api/knowledge-documents/{$document->id}/reindex"),
        'delete' => knRequest($this, $user, 'DELETE', "/api/knowledge-documents/{$document->id}"),
        'list deleted' => knRequest($this, $user, 'GET', '/api/knowledge-documents/deleted'),
        'restore' => knRequest($this, $user, 'POST', "/api/knowledge-documents/{$deleted->id}/restore"),
    };

    $call(fdEmployee($hotel, []))->assertForbidden();
    $call(employeeWithPermissions($hotel))->assertForbidden();
    expect($call(fdEmployee($hotel, [$permission]))->getStatusCode())->toBeIn([200, 201, 202]);
})->with([
    'list' => ['list', Permission::KNOWLEDGE_DOCUMENTS_VIEW],
    'show' => ['show', Permission::KNOWLEDGE_DOCUMENTS_VIEW],
    'download' => ['download', Permission::KNOWLEDGE_DOCUMENTS_VIEW],
    'view text' => ['view text', Permission::KNOWLEDGE_DOCUMENTS_VIEW],
    'upload' => ['upload', Permission::KNOWLEDGE_DOCUMENTS_CREATE],
    'edit' => ['edit', Permission::KNOWLEDGE_DOCUMENTS_UPDATE],
    'replace file' => ['replace file', Permission::KNOWLEDGE_DOCUMENTS_UPDATE],
    'correct text' => ['correct text', Permission::KNOWLEDGE_DOCUMENTS_UPDATE],
    'discard corrections' => ['discard corrections', Permission::KNOWLEDGE_DOCUMENTS_UPDATE],
    'reindex' => ['reindex', Permission::KNOWLEDGE_DOCUMENTS_REINDEX],
    'delete' => ['delete', Permission::KNOWLEDGE_DOCUMENTS_DELETE],
    'list deleted' => ['list deleted', Permission::KNOWLEDGE_DOCUMENTS_DELETE],
    'restore' => ['restore', Permission::KNOWLEDGE_DOCUMENTS_DELETE],
]);

it('gives employees without a role booking edits but never the capacity override', function () {
    expect(Permission::employeeDefaults())
        ->toContain(Permission::BOOKINGS_UPDATE)
        ->not->toContain(Permission::BOOKINGS_OVERRIDE_CAPACITY);
});

it('lets an employee run booking and activity actions only when their role grants it', function (string $action, Permission $permission) {
    test()->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);
    $booking = abBook($hotel, $activity, '2026-10-09', 1);
    app(BookingCancellationService::class)
        ->request($booking, Guest::withoutGlobalScope('hotel')->find($booking->guest_id), 'Flight changed');

    $call = fn ($user) => match ($action) {
        'edit booking' => abPatch($this, $user, "/api/booking/{$booking->id}", ['notes' => 'Window seat']),
        'activity availability' => fdGet($this, $user, "/api/activity/{$activity->id}/availability?from=2026-10-09"),
        'approve cancellation' => fdPost($this, $user, "/api/booking/{$booking->id}/cancellation-request/approve"),
        'decline cancellation' => fdPost($this, $user, "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'Non-refundable']),
        'override capacity' => fdPost($this, $user, '/api/booking', [
            'guest_id' => abGuest($hotel)->id,
            'activity_id' => $activity->id,
            'scheduled_date' => '2026-10-09',
            'pax' => 2,
            'charge_model' => 'pay_on_site',
            'capacity_override' => true,
        ]),
    };

    // The override is checked on top of taking the booking.
    $base = $action === 'override capacity' ? [Permission::BOOKINGS_CREATE] : [];

    $call(fdEmployee($hotel, $base))->assertForbidden();
    expect($call(fdEmployee($hotel, [...$base, $permission]))->status())->toBeIn([200, 201]);
})->with([
    'edit booking' => ['edit booking', Permission::BOOKINGS_UPDATE],
    'activity availability' => ['activity availability', Permission::ACTIVITIES_VIEW],
    'approve cancellation' => ['approve cancellation', Permission::BOOKINGS_UPDATE_STATUS],
    'decline cancellation' => ['decline cancellation', Permission::BOOKINGS_UPDATE_STATUS],
    'override capacity' => ['override capacity', Permission::BOOKINGS_OVERRIDE_CAPACITY],
]);

it('never gives employees without a role the approve-recommendations permission', function () {
    expect(Permission::employeeDefaults())->not->toContain(Permission::RECOMMENDATIONS_APPROVE);
});

it('lets an employee approve and reject recommendations only when their role grants it', function (string $action) {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel), 'pending_approval');

    [$method, $uri, $payload] = match ($action) {
        'approve' => ['POST', "/api/recommendation/{$recommendation->id}/approve", []],
        'reject' => ['POST', "/api/recommendation/{$recommendation->id}/reject", ['reason' => 'Closed']],
        'decide' => ['POST', '/api/recommendations/decide', ['action' => 'approve', 'ids' => [$recommendation->id]]],
    };

    rapRequest($this, fdEmployee($hotel, Permission::employeeDefaults()), $method, $uri, $payload)->assertForbidden();
    rapRequest($this, fdEmployee($hotel, [Permission::RECOMMENDATIONS_APPROVE]), $method, $uri, $payload)->assertOk();
})->with(['approve', 'reject', 'decide']);

it('lets an employee record a guest contact preference only with guests.update', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);
    $uri = "/api/guest/{$guest->id}/contact-preference";

    rapRequest($this, fdEmployee($hotel, Permission::employeeDefaults()), 'PUT', $uri, ['proactive_opted_out' => true])->assertForbidden();
    rapRequest($this, fdEmployee($hotel, [Permission::GUESTS_UPDATE]), 'PUT', $uri, ['proactive_opted_out' => true])->assertOk();
});

it('lets an employee upload and read reservation imports only with the import permission', function (string $endpoint) {
    Storage::fake('local');
    $hotel = hotelForPermissions();
    $granted = employeeWithPermissions($hotel, [Permission::RESERVATIONS_IMPORT]);
    $withoutIt = employeeWithPermissions($hotel, Permission::employeeDefaults());
    $csv = fn () => imCsv(['guest_phone', 'arrival_date', 'departure_date'], [['555-0100', '2026-09-01', '2026-09-04']]);

    if ($endpoint === 'upload') {
        imUpload($this, $withoutIt, $csv())->assertForbidden();
        imUpload($this, $granted, $csv())->assertStatus(202);

        return;
    }

    $import = imImport($this, $granted, $csv());
    $uri = str_replace('{id}', $import->id, $endpoint);

    $this->withHeaders(permissionApiHeaders())->actingAs($withoutIt, 'sanctum')->get($uri, ['Accept' => 'application/json'])->assertForbidden();
    $this->withHeaders(permissionApiHeaders())->actingAs($granted, 'sanctum')->get($uri, ['Accept' => 'application/json'])->assertOk();
})->with([
    'upload' => ['upload'],
    'history' => ['/api/reservation/imports'],
    'show' => ['/api/reservation/imports/{id}'],
    'rows' => ['/api/reservation/imports/{id}/rows'],
    'report' => ['/api/reservation/imports/{id}/report'],
]);

it('lets an employee upload, map and discard import previews only with the import permission (016)', function (string $method, string $endpoint, int $grantedStatus) {
    Storage::fake('local');
    $hotel = hotelForPermissions();
    $granted = employeeWithPermissions($hotel, [Permission::RESERVATIONS_IMPORT]);
    $withoutIt = employeeWithPermissions($hotel, Permission::employeeDefaults());
    $csv = fn () => imCsv(['guest_phone', 'arrival_date', 'departure_date'], [['555-0100', '2026-09-01', '2026-09-04']]);

    if ($endpoint === 'upload') {
        imPreview($this, $withoutIt, $csv())->assertForbidden();
        imPreview($this, $granted, $csv())->assertStatus($grantedStatus);

        return;
    }

    $previewId = imPreview($this, $granted, $csv())->assertCreated()->json('body.id');
    $uri = str_replace('{id}', $previewId, $endpoint);
    $body = ['header_row' => 1, 'columns' => ['0' => 'guest_phone', '1' => 'arrival_date', '2' => 'departure_date']];
    $call = fn ($user) => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')->json($method, $uri, $body);

    $call($withoutIt)->assertForbidden();
    $call($granted)->assertStatus($grantedStatus);
})->with([
    'upload' => ['POST', 'upload', 201],
    'list' => ['GET', '/api/reservation/import-previews', 200],
    'show' => ['GET', '/api/reservation/import-previews/{id}', 200],
    'confirm' => ['POST', '/api/reservation/import-previews/{id}/confirm', 202],
    'discard' => ['DELETE', '/api/reservation/import-previews/{id}', 200],
]);
