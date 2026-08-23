<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Recommendation::class);

        $query = Recommendation::with(['hotel', 'reservation.guest', 'activity']);

        if (! $request->user()->isSuperAdmin()) {
            $query->where('hotel_id', $request->user()->hotel?->id);
        }

        $recommendations = GenericQuery::apply($query, $request);

        return apiResponse('Recommendations fetched successfully.', 200, $recommendations);
    }

    /**
     * Display the specified resource.
     */
    public function show(Recommendation $recommendation)
    {
        $this->authorize('view', $recommendation);

        $recommendation->load(['hotel', 'reservation.guest', 'activity']);
        return apiResponse('Recommendation fetched successfully.', 200, $recommendation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, Recommendation $recommendation): JsonResponse
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

        $recommendation->update($validated);

        return apiResponse('Recommendation updated successfully.', 200, $recommendation->load(['hotel', 'reservation.guest', 'activity']));
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
     * Trigger activity-recommendation generation for a reservation's guest.
     */
    public function generate(Reservation $reservation): JsonResponse
    {
        $this->authorize('create', [Recommendation::class, $reservation]);

        GenerateActivityRecommendationsJob::dispatch($reservation);

        return apiResponse('Activity recommendations job has been initiated successfully.', 202, []);
    }
}
