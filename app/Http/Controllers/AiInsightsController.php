<?php

namespace App\Http\Controllers;

use App\Ai\Agents\InsightsAgent;
use App\Enums\AiInsightCategories;
use App\Enums\InsightTypes;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Models\AiInsights;
use App\Models\Hotel;
use App\Models\Message;
use App\Models\Reservation;
use App\Models\Task;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\Request;

class AiInsightsController extends Controller
{
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', AiInsights::class);
        // Fetch all AI insights from the database
        $insights = GenericQuery::apply(
            AiInsights::where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('AI insights fetched successfully.', 200, $insights);
    }

    public function store(GenericIndexRequest $request)
    {
        $this->authorize('create', AiInsights::class);

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $agent = InsightsAgent::make(
            user: $request->user(),
        );
        $response = match ($request->input('insight_type')) {
            'general' => $this->generalInsights($agent),
            'reservation' => $this->reservationInsights($agent),
            'task' => $agent->prompt('You are a helpful insights agent that provides insights about tasks.'),
            default => $agent->prompt('You are a helpful insights agent.'),
        };
        $insightType = $request->input('insight_type', InsightTypes::GENERAL->value);

        $insights = collect($response->structured['insights'])->map(function (array $insight) use ($insightType, $hotel) {
            $modelClass = $this->insightableModelFor($insight['category']);
            $sourceBelongsToHotel = $this->sourceBelongsToHotel($insight['category'], $insight['source_id'], $hotel);

            return AiInsights::create([
                'title' => $insight['title'],
                'hotel_id' => $hotel->id,
                'description' => $insight['description'],
                'category' => $insight['category'],
                'insight_type' => $insightType,
                'insightable_type' => $sourceBelongsToHotel ? $modelClass : null,
                'insightable_id' => $sourceBelongsToHotel ? $insight['source_id'] : null,
            ]);
        });

        return apiResponse('AI insights created successfully.', 201, $insights);
    }

    private function generalInsights(InsightsAgent $agent)
    {
        return $agent->prompt('Provide up to 5 actionable insights about overall hotel operations, drawing from today\'s reservations, open tasks, and recent guest messages.');
    }

    private function reservationInsights(InsightsAgent $agent)
    {
        return $agent->prompt('provide insights about today\'s reservations.');
    }

    private function insightableModelFor(string $category): string
    {
        return match ($category) {
            AiInsightCategories::RESERVATION->value => Reservation::class,
            AiInsightCategories::TASK->value => Task::class,
            AiInsightCategories::GUEST_MESSAGE->value => Message::class,
            AiInsightCategories::GENERAL->value => Hotel::class,
        };
    }

    /**
     * Verify a model-supplied source_id actually belongs to the given hotel,
     * since a hallucinated (or guest-message-injected) id could otherwise
     * point at another hotel's record despite existing in the database.
     */
    private function sourceBelongsToHotel(string $category, string $sourceId, Hotel $hotel): bool
    {
        return match ($category) {
            AiInsightCategories::RESERVATION->value => Reservation::where('hotel_id', $hotel->id)->whereKey($sourceId)->exists(),
            AiInsightCategories::TASK->value => Task::where('hotel_id', $hotel->id)->whereKey($sourceId)->exists(),
            AiInsightCategories::GUEST_MESSAGE->value => Message::whereHas(
                'conversation',
                fn ($query) => $query->where('hotel_id', $hotel->id)
            )->whereKey($sourceId)->exists(),
            AiInsightCategories::GENERAL->value => $sourceId === $hotel->id,
        };
    }
}
