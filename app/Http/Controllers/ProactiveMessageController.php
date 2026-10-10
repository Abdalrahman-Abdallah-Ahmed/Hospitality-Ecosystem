<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProactiveMessageIndexRequest;
use App\Http\Resources\ProactiveMessageResource;
use App\Models\ProactiveMessage;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;

/**
 * What the Concierge sent, or decided not to send, without being asked
 * (SPEC-073 FR-033). Read-only: rows are written by the proactive jobs.
 */
class ProactiveMessageController extends Controller
{
    public function index(ProactiveMessageIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ProactiveMessage::class);

        $timezone = $request->user()->hotel?->timezone ?: 'UTC';

        $query = ProactiveMessage::with('guest')
            ->when($request->input('sent_from'), fn ($query, string $date) => $query->where('sent_at', '>=', now()->parse($date, $timezone)->startOfDay()->utc()))
            ->when($request->input('sent_to'), fn ($query, string $date) => $query->where('sent_at', '<=', now()->parse($date, $timezone)->endOfDay()->utc()));

        if (! $request->filled('sort')) {
            $query->orderByDesc('due_at');
        }

        return apiResponse('Proactive messages fetched successfully.', 200, ProactiveMessageResource::collection(GenericQuery::apply($query, $request)));
    }

    public function show(ProactiveMessage $proactiveMessage): JsonResponse
    {
        $this->authorize('view', $proactiveMessage);

        return apiResponse('Proactive message fetched successfully.', 200, ProactiveMessageResource::make($proactiveMessage->load('guest')));
    }
}
