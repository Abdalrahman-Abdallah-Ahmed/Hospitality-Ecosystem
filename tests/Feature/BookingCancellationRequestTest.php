<?php

use App\Enums\BookingStatus;
use App\Enums\CancellationResolution;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Notifications\BookingCancellationRequestedNotification;
use App\Services\BookingCancellationService;
use App\Services\BookingService;
use App\Support\Audit\EventLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    $this->travelTo('2026-10-05 09:00:00');
    Notification::fake();
});

/**
 * A guest's booking and the request the Concierge made to cancel it.
 *
 * @return array{0: Hotel, 1: Booking, 2: Task}
 */
function abRequested(string $reason = 'Flight changed'): array
{
    $hotel = avHotel();
    $booking = abBook($hotel, abActivity($hotel, ['daily_capacity' => 6]), '2026-10-09', 2);
    $guest = Guest::withoutGlobalScope('hotel')->find($booking->guest_id);

    ['task' => $task] = EventLogger::asAiAgent(fn () => app(BookingCancellationService::class)->request($booking, $guest, $reason));

    return [$hotel, $booking, $task];
}

function abAdmin($hotel): User
{
    return User::find($hotel->owner_id);
}

it('emails each hotel admin once, and nothing else', function () {
    [$hotel, $booking] = abRequested();
    $secondAdmin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);
    $guest = Guest::withoutGlobalScope('hotel')->find($booking->guest_id);

    // Asking again sends nothing more.
    EventLogger::asAiAgent(fn () => app(BookingCancellationService::class)->request($booking, $guest, 'again'));

    Notification::assertSentToTimes(abAdmin($hotel), BookingCancellationRequestedNotification::class, 1);
    Notification::assertNotSentTo($secondAdmin, BookingCancellationRequestedNotification::class);
    Notification::assertNotSentTo(abAdmin($hotel), AiTaskCreatedNotification::class);
});

it('emails every admin of the hotel', function () {
    $hotel = avHotel();
    $second = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);
    $booking = abBook($hotel, abActivity($hotel), '2026-10-09', 1);

    app(BookingCancellationService::class)->request($booking, Guest::withoutGlobalScope('hotel')->find($booking->guest_id), null);

    Notification::assertSentTo([abAdmin($hotel), $second], BookingCancellationRequestedNotification::class);
});

it('shows the booking as having an open request', function () {
    [$hotel, $booking] = abRequested();

    fdGet($this, abAdmin($hotel), "/api/booking/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('body.cancellation_requested', true)
        ->assertJsonPath('body.status', BookingStatus::PENDING->value);
});

it('approves a request by cancelling the booking with the guest reason', function () {
    [$hotel, $booking, $task] = abRequested();

    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/cancellation-request/approve")
        ->assertOk()
        ->assertJsonPath('body.status', BookingStatus::CANCELLED->value)
        ->assertJsonPath('body.cancellation_requested', false);

    $task->refresh();

    expect($booking->fresh()->cancellation_reason)->toBe('Flight changed')
        ->and($task->status)->toBe(TaskStatus::COMPLETED)
        ->and($task->resolution)->toBe(CancellationResolution::APPROVED)
        ->and($task->resolved_by_user_id)->toBe($hotel->owner_id)
        ->and($task->resolved_at)->not->toBeNull();
});

it('closes the request as approved when the booking is cancelled from the status endpoint', function () {
    [$hotel, $booking, $task] = abRequested();

    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'At the desk'])->assertOk();

    expect($task->fresh()->resolution)->toBe(CancellationResolution::APPROVED)
        ->and($booking->fresh()->cancellation_reason)->toBe('At the desk');
});

it('declines a request with a note and leaves the booking as it was', function () {
    [$hotel, $booking, $task] = abRequested();
    $admin = abAdmin($hotel);

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/decline")->assertStatus(422)->assertJsonValidationErrors('note');

    fdPost($this, $admin, "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'Non-refundable within 24 hours'])
        ->assertOk()
        ->assertJsonPath('body.resolution', 'declined')
        ->assertJsonPath('body.resolution_note', 'Non-refundable within 24 hours');

    expect($booking->fresh()->status)->toBe(BookingStatus::PENDING)
        ->and($task->fresh()->status)->toBe(TaskStatus::COMPLETED)
        ->and(EventLog::where('subject_id', $booking->id)->where('event_type', 'booking.cancellation_declined')->exists())->toBeTrue();
});

it('keeps the request open when the booking can no longer be cancelled', function () {
    [$hotel, $booking, $task] = abRequested();
    app(BookingService::class)->realise($booking);

    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/cancellation-request/approve")->assertStatus(422);

    expect($task->fresh()->isOpen())->toBeTrue();
});

it('answers 404 when there is no open request', function () {
    $hotel = avHotel();
    $booking = abBook($hotel, abActivity($hotel), '2026-10-09', 1);

    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/cancellation-request/approve")->assertNotFound();
    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'x'])->assertNotFound();
});

it('needs bookings.update_status to answer a request', function () {
    [$hotel, $booking] = abRequested();

    fdPost($this, fdEmployee($hotel, [Permission::BOOKINGS_VIEW]), "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'x'])
        ->assertForbidden();
    fdPost($this, fdEmployee($hotel, [Permission::BOOKINGS_UPDATE_STATUS]), "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'x'])
        ->assertOk();
});

it('lists open requests oldest first, without answered ones', function () {
    [$hotel, $first] = abRequested('first');
    $activity = abActivity($hotel);
    $service = app(BookingCancellationService::class);

    $this->travelTo('2026-10-05 10:00:00');
    $second = abBook($hotel, $activity, '2026-10-09', 1);
    $service->request($second, Guest::withoutGlobalScope('hotel')->find($second->guest_id), 'second');

    $this->travelTo('2026-10-05 11:00:00');
    $answered = abBook($hotel, $activity, '2026-10-09', 1);
    $service->request($answered, Guest::withoutGlobalScope('hotel')->find($answered->guest_id), 'answered');
    $this->actingAs(abAdmin($hotel));
    $service->decline($answered, 'no');

    $items = fdGet($this, abAdmin($hotel), '/api/booking/cancellation-requests')->assertOk()->json('body.data');

    expect(array_column($items, 'reason'))->toBe(['first', 'second'])
        ->and($items[0]['booking']['reference'])->toBe($first->reference)
        ->and($items[0]['booking']['activity']['name'])->toBe('Sunset cruise')
        ->and($items[0]['guest']['first_name'])->toBe('Guest');
});

it('still records the request when the hotel has no admin to email', function () {
    Log::spy();
    $hotel = avHotel();
    User::whereKey($hotel->owner_id)->update(['role' => UserRole::EMPLOYEE]);
    $booking = abBook($hotel, abActivity($hotel), '2026-10-09', 1);

    ['created' => $created] = app(BookingCancellationService::class)->request($booking, Guest::withoutGlobalScope('hotel')->find($booking->guest_id), null);

    expect($created)->toBeTrue();
    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'no hotel admin'))->once();
});

it('lets the database refuse a second open request for the same booking', function () {
    [$hotel, $booking] = abRequested();

    expect(fn () => Task::withoutGlobalScope('hotel')->create([
        'hotel_id' => $hotel->id,
        'title' => 'Duplicate',
        'created_by' => 'guest',
    ])->forceFill(['booking_id' => $booking->id, 'guest_signal' => 'cancellation_request'])->save())
        ->toThrow(QueryException::class);
});

it('will not close or delete an open request as a plain task', function () {
    [$hotel, , $task] = abRequested();
    $admin = abAdmin($hotel);

    hkPut($this, $admin, "/api/task/{$task->id}", ['status' => 'completed'])->assertStatus(422);
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')->deleteJson("/api/task/{$task->id}")->assertStatus(422);

    expect($task->fresh()->isOpen())->toBeTrue()
        ->and(Transaction::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('still lets staff pick up or reassign an open request', function () {
    [$hotel, , $task] = abRequested();
    $admin = abAdmin($hotel);

    hkPut($this, $admin, "/api/task/{$task->id}", ['status' => 'in_progress'])->assertOk();
    hkPut($this, $admin, "/api/task/{$task->id}", ['status' => 'in_progress', 'assigned_to_user_id' => $admin->id])->assertOk();

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('cannot approve a request someone already declined', function () {
    [$hotel, $booking] = abRequested();
    $this->actingAs(abAdmin($hotel));
    $service = app(BookingCancellationService::class);

    $service->decline($booking, 'Non-refundable');

    expect(fn () => $service->approve($booking, null))->toThrow(RuntimeException::class)
        ->and($booking->fresh()->status)->toBe(BookingStatus::PENDING);

    fdPost($this, abAdmin($hotel), "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'again'])->assertNotFound();
});
