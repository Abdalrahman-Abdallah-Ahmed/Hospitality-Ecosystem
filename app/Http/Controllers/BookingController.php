<?php

namespace App\Http\Controllers;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Recommendation;
use App\Services\BookingService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
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
 * There is no update and no delete: a booking is cancelled, with a reason.
 */
class BookingController extends Controller
{
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Booking::class);

        $query = Booking::with(['guest', 'activity', 'recommendation'])
            ->orderBy('scheduled_for');

        return apiResponse('Bookings fetched successfully.', 200, GenericQuery::apply($query, $request));
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        return apiResponse('Booking fetched successfully.', 200, $booking->load([
            'guest', 'activity', 'recommendation', 'stay', 'transactions',
        ]));
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

        $validated = $request->validated();

        // exists:… only proves the row exists somewhere, not that it is ours.
        if ($invalid = invalidRelation($hotel, [
            'guests' => $validated['guest_id'],
            'activities' => $validated['activity_id'] ?? null,
            'stays' => $validated['stay_id'] ?? null,
        ])) {
            return apiResponse('The selected '.Str::singular($invalid).' does not belong to this hotel.', 422);
        }

        $activity = isset($validated['activity_id'])
            ? Activity::where('hotel_id', $hotel->id)->find($validated['activity_id'])
            : null;

        $recommendation = isset($validated['recommendation_id'])
            ? Recommendation::where('hotel_id', $hotel->id)->find($validated['recommendation_id'])
            : null;

        if (isset($validated['recommendation_id']) && ! $recommendation) {
            return apiResponse('The selected recommendation does not belong to this hotel.', 422);
        }

        $booking = app(BookingService::class)->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $validated['guest_id'],
            'stay_id' => $validated['stay_id'] ?? null,
            'activity_id' => $activity?->id,
            'recommendation_id' => $recommendation?->id,
            'item_name' => $validated['item_name'] ?? $activity?->name,
            'scheduled_for' => isset($validated['scheduled_for'])
                ? Carbon::parse($validated['scheduled_for'])
                : null,
            'pax' => $validated['pax'] ?? 1,
            'charge_model' => $validated['charge_model'],
            'expected_value' => $validated['expected_value'] ?? $activity?->price,
            'currency' => $validated['currency'] ?? $activity?->currency ?? $hotel->currency,
            // Derived, never taken from the request: a booking may only claim
            // recommendation origin when it actually carries the link.
            'origin' => $recommendation
                ? BookingOrigin::RECOMMENDATION->value
                : ($validated['origin'] ?? BookingOrigin::STAFF->value),
            'channel' => $validated['channel'] ?? 'desk',
            'created_by_user_id' => $request->user()->id,
        ]);

        return apiResponse('Booking created successfully.', 201, $booking->load(['guest', 'activity']));
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

        return apiResponse('Booking status updated successfully.', 200, $booking->fresh()->load(['guest', 'activity']));
    }
}
