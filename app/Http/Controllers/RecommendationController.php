<?php

namespace App\Http\Controllers;

use App\Enums\ActorKind;
use App\Enums\AttributionMethod;
use App\Enums\DeliveryChannel;
use App\Enums\OutcomeType;
use App\Http\Requests\DecideRecommendationsRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Requests\RecommendationIndexRequest;
use App\Http\Requests\RecordRecommendationOutcomeRequest;
use App\Http\Requests\RejectRecommendationRequest;
use App\Http\Resources\RecommendationOutcomeResource;
use App\Http\Resources\RecommendationResource;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Models\Booking;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\RecommendationDeliveryService;
use App\Services\RecommendationOutcomeService;
use App\Services\Recommendations\RecommendationApprovalService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use RuntimeException;

class RecommendationController extends Controller
{
    /**
     * Display a listing of the resource — also the approval queue
     * (`status=pending_approval`).
     */
    public function index(RecommendationIndexRequest $request)
    {
        $this->authorize('viewAny', Recommendation::class);

        $query = Recommendation::with(['hotel', 'reservation.guest', 'activity', 'reviewedBy'])
            ->when($request->statuses(), fn ($query, array $statuses) => $query->whereIn('status', $statuses))
            ->when($request->input('source'), fn ($query, string $source) => $query->where('source', $source))
            ->when($request->input('reservation_id'), fn ($query, string $id) => $query->where('reservation_id', $id))
            ->when($request->input('activity_id'), fn ($query, string $id) => $query->where('activity_id', $id))
            ->when($request->input('guest_id'), fn ($query, string $id) => $query->whereHas('reservation', fn ($reservation) => $reservation->where('guest_id', $id)))
            ->when($request->input('arrival_from'), fn ($query, string $date) => $query->whereHas('reservation', fn ($reservation) => $reservation->whereDate('arrival_date', '>=', $date)))
            ->when($request->input('arrival_to'), fn ($query, string $date) => $query->whereHas('reservation', fn ($reservation) => $reservation->whereDate('arrival_date', '<=', $date)));

        // The reservation's arrival is not a column of recommendations, so it
        // is ordered here and kept out of the generic sort.
        $sort = $request->string('sort')->toString();

        if (ltrim($sort, '-') === RecommendationIndexRequest::ARRIVAL_SORT) {
            $query->orderBy(
                Reservation::select('arrival_date')->whereColumn('reservations.id', 'recommendations.reservation_id'),
                str_starts_with($sort, '-') ? 'desc' : 'asc',
            );
            $request->merge(['sort' => null]);
        }

        $recommendations = GenericQuery::apply($query, $request);

        return apiResponse('Recommendations fetched successfully.', 200, RecommendationResource::collection($recommendations));
    }

    /**
     * Display the specified resource.
     */
    public function show(Recommendation $recommendation)
    {
        $this->authorize('view', $recommendation);

        $recommendation->load(['hotel', 'reservation.guest', 'activity', 'reviewedBy']);

        return apiResponse('Recommendation fetched successfully.', 200, RecommendationResource::make($recommendation));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Recommendation $recommendation, RecommendationApprovalService $approvals): JsonResponse
    {
        $this->authorize('update', $recommendation);

        // status, the *_at timestamps, and guest_confidence reflect the
        // guest's own response, captured live by the AI concierge — an
        // admin editing this record isn't the guest, so those stay
        // out of reach here (see App\Ai\Tools\UpdateRecommendationTool).
        $validated = unsetAttributes($request->validated(), [
            'hotel_id',
            'status',
            'guest_confidence',
            'accepted_at',
            'rejected_at',
            'dismissed_at',
        ]);

        $invalidRelation = invalidRelation($recommendation->hotel, [
            'reservations' => $validated['reservation_id'] ?? null,
            'activities' => $validated['activity_id'] ?? null,
        ]);

        if ($invalidRelation) {
            return apiResponse("The selected {$invalidRelation} does not belong to you.", 403);
        }

        // Changing what the guest would be offered sends an approved
        // recommendation back for review (SPEC-071 FR-006a). Approval itself
        // only changes through approve() and reject().
        try {
            $approvals->applyEdit($recommendation, unsetAttributes($validated, ['source', 'reviewed_by_user_id', 'reviewed_at', 'review_reason']));
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Recommendation updated successfully.', 200, RecommendationResource::make($recommendation->load(['hotel', 'reservation.guest', 'activity', 'reviewedBy'])));
    }

    /**
     * Approve a recommendation, so the Concierge may offer it to the guest.
     */
    public function approve(Recommendation $recommendation, RecommendationApprovalService $approvals): JsonResponse
    {
        $this->authorize('approve', $recommendation);

        try {
            $approvals->approve($recommendation, request()->user());
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Recommendation approved successfully.', 200, RecommendationResource::make($recommendation->load(['reservation.guest', 'activity', 'reviewedBy'])));
    }

    /**
     * Reject a recommendation before any guest hears it.
     */
    public function reject(RejectRecommendationRequest $request, Recommendation $recommendation, RecommendationApprovalService $approvals): JsonResponse
    {
        $this->authorize('approve', $recommendation);

        try {
            $approvals->reject($recommendation, $request->user(), $request->validated('reason'));
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Recommendation rejected successfully.', 200, RecommendationResource::make($recommendation->load(['reservation.guest', 'activity', 'reviewedBy'])));
    }

    /**
     * Approve or reject several recommendations at once. Each is decided and
     * audited on its own; ones that cannot be decided are reported, not fatal.
     */
    public function decide(DecideRecommendationsRequest $request, RecommendationApprovalService $approvals): JsonResponse
    {
        $this->authorize('approve', Recommendation::class);

        $result = $approvals->decideMany(
            $request->validated('ids'),
            $request->validated('action'),
            $request->user(),
            $request->validated('reason'),
        );

        return apiResponse('Recommendations decided successfully.', 200, $result);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Recommendation $recommendation)
    {
        $this->authorize('delete', $recommendation);
        $recommendation->delete();

        return apiResponse('Recommendation deleted successfully.', 200);
    }

    /**
     * Record what happened to a recommendation, as observed by a person.
     *
     * The staff capture path. It matters most for refusals: a guest who says
     * no produces no booking and no payment, so nothing downstream contains
     * that fact. Everything written here is STAFF (L1) — a human at the point
     * of contact saw it.
     */
    public function recordOutcome(RecordRecommendationOutcomeRequest $request, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('recordOutcome', $recommendation);

        $validated = $request->validated();
        $booking = null;

        if ($bookingId = $validated['booking_id'] ?? null) {
            // exists:bookings,id only proves the row exists somewhere.
            $booking = Booking::where('hotel_id', $recommendation->hotel_id)->find($bookingId);

            if (! $booking) {
                return apiResponse('The selected booking does not belong to this hotel.', 422);
            }
        }

        $outcomeType = OutcomeType::from($validated['outcome']);

        try {
            $outcome = app(RecommendationOutcomeService::class)->record(
                $recommendation,
                $outcomeType,
                AttributionMethod::STAFF,
                $booking,
                [
                    'channel' => $validated['channel'] ?? null,
                    'decline_reason' => $validated['decline_reason'] ?? null,
                    'evidence_quote' => $validated['evidence_quote'] ?? null,
                    'occurred_at' => $validated['occurred_at'] ?? null,
                    'recorded_by_user_id' => $request->user()->id,
                ],
            );
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        // A person saw the guest react to it, so it was offered. Stamped after
        // the outcome, so a first-time entry does not read as a reaction in
        // zero minutes. NOT_DELIVERED is the one outcome that proves nothing
        // was offered.
        if ($outcomeType !== OutcomeType::NOT_DELIVERED) {
            app(RecommendationDeliveryService::class)->markDelivered(
                $recommendation,
                DeliveryChannel::fromRecorded($validated['channel'] ?? null),
                isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : now(),
                ActorKind::USER,
            );
        }

        return apiResponse('Recommendation outcome recorded successfully.', 201, RecommendationOutcomeResource::make($outcome));
    }

    /**
     * Trigger activity-recommendation generation for a reservation's guest.
     */
    public function generate(Reservation $reservation): JsonResponse
    {
        $this->authorize('create', [Recommendation::class, $reservation]);

        GenerateActivityRecommendationsJob::dispatch($reservation);

        return apiResponse('Activity recommendations job has been initiated successfully.', 202, []);
    }
}
