<?php

namespace App\Http\Controllers;

use App\Enums\AiActivityCategory;
use App\Http\Requests\AiActivityIndexRequest;
use App\Http\Resources\AiActivityResource;
use App\Models\AiActivity;
use App\Models\AiUsageLog;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\User;
use App\Support\Audit\HistorySubjects;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Everything the AI did for the hotel, in one list (SPEC-083 FR-025): runs,
 * the changes they made or attempted, and guest outreach. Read under the
 * same permission as the record history.
 */
class AiActivityController extends Controller
{
    public function index(AiActivityIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EventLog::class);

        $hotel = resolveHotel($request->user(), $request->input('hotel_id'))
            ?? abort(422, 'Choose a hotel (hotel_id) to read its AI activity.');
        $timezone = $hotel->timezone ?: 'UTC';

        $page = AiActivity::query()
            ->where('hotel_id', $hotel->id)
            ->when($request->validated('category'), fn (Builder $query, array $categories) => $query->whereIn('category', $categories))
            ->when($request->validated('agent'), fn (Builder $query, string $agent) => $query->where('agent', $agent))
            ->when($request->validated('tool'), fn (Builder $query, string $tool) => $query->where('tool', $tool))
            ->when($request->validated('result'), fn (Builder $query, string $result) => $query->where('result', $result))
            ->when($request->validated('conversation_id'), fn (Builder $query, string $id) => $query->where('conversation_id', $id))
            ->when($request->validated('ai_run_id'), fn (Builder $query, string $id) => $query->where('ai_run_id', $id))
            ->when($request->validated('acted_for_type'), fn (Builder $query, string $type) => $query
                ->where('acted_for_type', $type === 'guest' ? (new Guest)->getMorphClass() : (new User)->getMorphClass())
                ->when($request->validated('acted_for_id'), fn (Builder $query, string $id) => $query->where('acted_for_id', $id)))
            ->when($request->validated('target_type'), fn (Builder $query, string $slug) => $query->where('target_type', (new (HistorySubjects::classFor($slug)))->getMorphClass()))
            ->when($request->validated('from'), fn (Builder $query, string $date) => $query->where('occurred_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($request->validated('to'), fn (Builder $query, string $date) => $query->where('occurred_at', '<=', CarbonImmutable::parse($date, $timezone)->endOfDay()->utc()))
            ->with('actedFor')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        if ($request->user()->isSuperAdmin()) {
            $this->attachRunCosts($page->getCollection());
        }

        return apiResponse('AI activity fetched successfully.', 200, AiActivityResource::collection($page));
    }

    /**
     * What each run on the page cost, in one query, for super admins only.
     *
     * @param  Collection<int, AiActivity>  $activities
     */
    private function attachRunCosts($activities): void
    {
        $runIds = $activities->filter(fn (AiActivity $activity) => $activity->category === AiActivityCategory::AI_RUN)->pluck('id');

        $costs = AiUsageLog::query()
            ->whereIn('ai_run_id', $runIds)
            ->groupBy('ai_run_id')
            ->selectRaw('ai_run_id, SUM(cost_usd) AS cost')
            ->pluck('cost', 'ai_run_id');

        $activities->each(fn (AiActivity $activity) => $activity->setAttribute('run_cost_usd', $costs[$activity->id] ?? null));
    }
}
