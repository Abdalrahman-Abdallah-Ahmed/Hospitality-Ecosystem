<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\BookingIndexRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Stay;
use App\Services\BookingService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The staff-facing view of commitments.
 *
 * Its most important route is the status one. Only the person at the outlet
 * can observe whether a guest actually turned up — not the agent, not the
 * ledger (an included activity produces no payment), not the importer. Without
 * it, `realised_at` is never set and the conversion report's realisation rate
 * cannot be produced at all.
 *
 * A live booking's date, time, party and notes can be corrected, checked
 * against the activity's availability like a new booking (SPEC-043). Who it
 * is for and how it came about never change, and there is no delete: a
 * booking is cancelled, with a reason.
 */
class BookingController extends Controller
{
    /**
     * The booking list and calendar: by activity, reservation and date range,
     * and the ones a guest has asked to cancel.
     */
    public function index(BookingIndexRequest $request)
    {
        $this->authorize('viewAny', Booking::class);

        $query = Booking::with(['guest', 'activity', 'recommendation'])
            ->withExists('openCancellationRequest as cancellation_requested')
            ->when($request->filled('activity_id'), fn ($query) => $query->where('activity_id', $request->input('activity_id')))
            ->when($request->filled('reservation_id'), fn ($query) => $query->where('reservation_id', $request->input('reservation_id')))
            ->when($request->filled('scheduled_from'), fn ($query) => $query->where('scheduled_date', '>=', $request->input('scheduled_from')))
            ->when($request->filled('scheduled_to'), fn ($query) => $query->where('scheduled_date', '<=', $request->input('scheduled_to')))
            ->when($request->has('cancellation_requested'), fn ($query) => $request->boolean('cancellation_requested')
                ? $query->whereHas('openCancellationRequest')
                : $query->whereDoesntHave('openCancellationRequest'))
            ->when(! $request->filled('sort'), fn ($query) => $query->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('scheduled_for'));

        return apiResponse('Bookings fetched successfully.', 200, BookingResource::collection(GenericQuery::apply($query, $request)));
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        return apiResponse('Booking fetched successfully.', 200, BookingResource::make($booking->load([
            'guest', 'activity', 'recommendation', 'stay', 'transactions',
        ])->loadExists('openCancellationRequest as cancellation_requested')));
    }

    /**
     * Take a booking on the guest's behalf — the walk-up at the desk.
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $override = $this->capacityOverride($request);

        // Shared with the Admin AI: the hotel checks, reservation-from-stay
        // and derived origin live in BookingService.
        $booking = app(BookingService::class)->takeStaffBooking($hotel, $request->user(), $request->validated(), $override);

        return apiResponse('Booking created successfully.', 201, BookingResource::make($booking->load(['guest', 'activity'])->loadExists('openCancellationRequest as cancellation_requested')));
    }

    /**
     * Correct a live booking: its activity, date, time, party, notes or the
     * reservation it belongs to. A change to what it holds is checked against
     * availability; its own places never count against it.
     */
    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        $override = $this->capacityOverride($request);
        $validated = $request->validated();

        if ($invalid = invalidRelation($booking->hotel, [
            'activities' => $validated['activity_id'] ?? null,
            'stays' => $validated['stay_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
        ])) {
            return apiResponse('The selected '.Str::singular($invalid).' does not belong to this hotel.', 422);
        }

        // Only a stay or a reservation actually given moves the booking to a
        // reservation. Removing the stay alone keeps the reservation it has.
        $stayId = array_key_exists('stay_id', $validated) ? $validated['stay_id'] : $booking->stay_id;

        if (array_key_exists('reservation_id', $validated) || ($validated['stay_id'] ?? null) !== null) {
            $reservationId = app(BookingService::class)->reservationFor(
                $stayId,
                array_key_exists('reservation_id', $validated) ? $validated['reservation_id'] : null,
            );

            if ($reservationId === false) {
                return apiResponse('The selected stay does not belong to the selected reservation.', 422);
            }

            $validated['reservation_id'] = $reservationId;
        }

        unset($validated['capacity_override']);

        try {
            $booking = app(BookingService::class)->update($booking, [...$validated, 'capacity_override' => $override]);
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Booking updated successfully.', 200, BookingResource::make($booking->load(['guest', 'activity'])->loadExists('openCancellationRequest as cancellation_requested')));
    }

    /**
     * Move the booking through its lifecycle. This is what makes the
     * realisation rate computable at all.
     */
    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('updateStatus', $booking);

        $validated = $request->validated();
        $service = app(BookingService::class);

        try {
            $booking = match (BookingStatus::from($validated['status'])) {
                BookingStatus::CONFIRMED => $service->confirm($booking),
                BookingStatus::REALISED => $service->realise(
                    $booking,
                    isset($validated['realised_at']) ? Carbon::parse($validated['realised_at']) : null,
                ),
                BookingStatus::NO_SHOW => $service->markNoShow($booking),
                BookingStatus::CANCELLED => $service->cancel($booking, $validated['reason']),
                default => $booking,
            };
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Booking status updated successfully.', 200, BookingResource::make($booking->fresh()->load(['guest', 'activity'])->loadExists('openCancellationRequest as cancellation_requested')));
    }

    /**
     * Whether staff asked to book past the activity's capacity. Asking needs
     * the permission; not asking needs nothing (spec Q3).
     */
    private function capacityOverride(Request $request): bool
    {
        if (! $request->boolean('capacity_override')) {
            return false;
        }

        $this->authorize('overrideCapacity', Booking::class);

        return true;
    }
}
