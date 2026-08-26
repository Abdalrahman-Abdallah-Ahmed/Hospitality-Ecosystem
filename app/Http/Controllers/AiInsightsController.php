<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Jobs\CreateAiInsightsJob;
use App\Models\AiInsights;
use App\Support\RequestRules\GenericQuery;

class AiInsightsController extends Controller
{
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', AiInsights::class);

        $query = AiInsights::query();

        $insights = GenericQuery::apply($query, $request);

        return apiResponse('AI insights fetched successfully.', 200, $insights);
    }

    public function store(GenericIndexRequest $request)
    {
        $this->authorize('create', AiInsights::class);

        $hotel = $request->user()->hotel;

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $data = [
            'insight_type' => $request->input('insight_type'),
            'user' => $request->user(),
        ];

        CreateAiInsightsJob::dispatch($data);

        return apiResponse('AI insights job has been initiated successfully.', 201, []);
    }
}
