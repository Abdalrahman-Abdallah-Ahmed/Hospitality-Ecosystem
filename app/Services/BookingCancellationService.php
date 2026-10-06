<?php

namespace App\Services;

use App\Enums\CancellationResolution;
use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\TaskStatus;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Task;
use App\Support\Audit\EventLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A guest's request to cancel a booking, and staff's answer (SPEC-043).
 *
 * The Guest Concierge never cancels a booking: it can only ask. The request is
 * a task with no team, linked to the booking, which staff approve (the booking
 * is cancelled) or decline (it stands). There is at most one open request per
 * booking, enforced by a unique index as well as here.
 *
 * Every query names the hotel: the Concierge runs without tenant context.
 */
class BookingCancellationService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly CreationNotificationService $notifications,
    ) {}

    /**
     * Ask staff to cancel the guest's booking. Returns the request and
     * whether it is new: asking again while one is open returns that one, and
     * only a new request emails the admins.
     *
     * @return array{task: Task, created: bool}
     *
     * @throws RuntimeException when the booking is not this guest's, or can no longer be cancelled
     */
    public function request(Booking $booking, Guest $guest, ?string $reason): array
    {
        if ($booking->guest_id !== $guest->id || $booking->hotel_id !== $guest->hotel_id) {
            throw new RuntimeException('That booking does not belong to this guest.');
        }

        try {
            $result = DB::transaction(function () use ($booking, $reason) {
                // Serializes two requests for the same booking.
                $booking = Booking::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($booking->id);

                if (! $booking->status->isEditable()) {
                    throw new RuntimeException("The booking is already {$booking->status->value}, so it cannot be cancelled.");
                }

                if ($open = $this->openRequest($booking)) {
                    return ['task' => $open, 'created' => false];
                }

                $task = new Task([
                    'hotel_id' => $booking->hotel_id,
                    'guest_id' => $booking->guest_id,
                    'reservation_id' => $booking->reservation_id,
                    'stay_id' => $booking->stay_id,
                    'title' => "Cancellation request: {$booking->item_name} on ".($booking->scheduled_date?->toDateString() ?? 'no date')." ({$booking->reference})",
                    'description' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    'created_by' => CreatedBy::GUEST,
                    'status' => TaskStatus::PENDING,
                ]);
                $task->forceFill([
                    'booking_id' => $booking->id,
                    'guest_signal' => GuestSignal::CANCELLATION_REQUEST,
                ])->save();

                EventLogger::record($booking, 'cancellation_requested', changes: ['task_id' => $task->id, 'reason' => $task->description]);

                // Queued after commit, so a rolled-back request emails nobody.
                $this->notifications->bookingCancellationRequested($task);

                return ['task' => $task, 'created' => true];
            });
        } catch (QueryException $e) {
            // Another request won the race to the unique index.
            if ($e->getCode() !== '23505' || ! ($open = $this->openRequest($booking))) {
                throw $e;
            }

            return ['task' => $open, 'created' => false];
        }

        return $result;
    }

    /**
     * Staff say yes: the booking is cancelled, which closes the request as
     * approved (BookingService::cancel). The reason defaults to the guest's.
     *
     * @throws RuntimeException when there is no open request, or the booking can no longer be cancelled
     */
    public function approve(Booking $booking, ?string $reason): Booking
    {
        // Locked, so an approve and a decline racing for the same request
        // cannot both win: the second finds it already answered.
        return DB::transaction(function () use ($booking, $reason) {
            $open = $this->openRequest($booking, lock: true) ?? throw new RuntimeException('There is no open cancellation request for this booking.');

            return $this->bookings->cancel($booking, $reason ?: ($open->description ?: 'Cancelled at the guest\'s request.'));
        });
    }

    /**
     * Staff say no: the booking stands, and the request is closed with the
     * note so the guest can be told why.
     *
     * @throws RuntimeException when there is no open request
     */
    public function decline(Booking $booking, string $note): Task
    {
        return DB::transaction(function () use ($booking, $note) {
            $open = $this->openRequest($booking, lock: true) ?? throw new RuntimeException('There is no open cancellation request for this booking.');

            $open->forceFill([
                'status' => TaskStatus::COMPLETED,
                'resolution' => CancellationResolution::DECLINED,
                'resolution_note' => $note,
                'resolved_by_user_id' => Auth::id(),
                'resolved_at' => now(),
            ])->save();

            EventLogger::record($booking, 'cancellation_declined', changes: ['task_id' => $open->id, 'note' => $note]);

            return $open;
        });
    }

    public function openRequest(Booking $booking, bool $lock = false): ?Task
    {
        return Task::withoutGlobalScope('hotel')
            ->where('hotel_id', $booking->hotel_id)
            ->where('booking_id', $booking->id)
            ->where('guest_signal', GuestSignal::CANCELLATION_REQUEST->value)
            ->open()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
