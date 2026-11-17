<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
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

function guestForPermissions(Hotel $hotel): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);
}

it('never gives employees without a role the voucher permissions', function () {
    $voucher = [
        Permission::RESERVATIONS_VOUCHER, Permission::RESERVATIONS_VOUCHER_EMAIL,
        Permission::VOUCHER_TEMPLATES_VIEW, Permission::VOUCHER_TEMPLATES_CREATE,
        Permission::VOUCHER_TEMPLATES_UPDATE, Permission::VOUCHER_TEMPLATES_DELETE,
    ];

    foreach ($voucher as $permission) {
        expect(Permission::employeeDefaults())->not->toContain($permission);
    }

    $listed = $this->withHeaders(permissionApiHeaders())->actingAs(adminOfHotel(hotelForPermissions()), 'sanctum')
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
    $listed = collect($this->withHeaders(permissionApiHeaders())->actingAs(adminOfHotel($hotel), 'sanctum')
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
