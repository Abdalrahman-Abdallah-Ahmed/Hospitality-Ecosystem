<?php

namespace App\Http\Controllers;

use App\Enums\GuestSignal;
use App\Http\Requests\ApproveCancellationRequest;
use App\Http\Requests\DeclineCancellationRequest;
use App\Http\Resources\BookingCancellationRequestResource;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Task;
use App\Services\BookingCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The staff side of guest cancellation requests (SPEC-043). The Concierge
 * only asks; staff approve (the booking is cancelled) or decline (it stands,
 * and the note says why).
 */
class BookingCancellationRequestController extends Controller
{
    public function __construct(private readonly BookingCancellationService $cancellations) {}

    /**
     * Open requests, oldest first: the ones waiting longest are answered first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $requests = Task::query()
            ->where('guest_signal', GuestSignal::CANCELLATION_REQUEST->value)
            ->open()
            ->with(['guest', 'booking.activity'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return apiResponse('Cancellation requests fetched successfully.', 200, BookingCancellationRequestResource::collection($requests));
    }

    public function approve(ApproveCancellationRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('updateStatus', $booking);

        if (! $this->cancellations->openRequest($booking)) {
            return apiResponse('There is no open cancellation request for this booking.', 404);
        }

        try {
            $booking = $this->cancellations->approve($booking, $request->validated('reason'));
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Booking cancelled at the guest\'s request.', 200, BookingResource::make($booking->fresh()->load(['guest', 'activity'])->loadExists('openCancellationRequest as cancellation_requested')));
    }

    public function decline(DeclineCancellationRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('updateStatus', $booking);

        if (! $this->cancellations->openRequest($booking)) {
            return apiResponse('There is no open cancellation request for this booking.', 404);
        }

        try {
            $task = $this->cancellations->decline($booking, $request->validated('note'));
        } catch (RuntimeException $e) {
            // Answered by someone else since the check above.
            return apiResponse($e->getMessage(), 404);
        }

        return apiResponse('Cancellation request declined.', 200, BookingCancellationRequestResource::make($task->load(['guest', 'booking.activity'])));
    }
}
