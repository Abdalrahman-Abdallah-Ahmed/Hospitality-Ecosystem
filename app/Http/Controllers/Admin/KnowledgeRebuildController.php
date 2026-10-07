<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KnowledgeRebuildScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StartKnowledgeRebuildRequest;
use App\Http\Resources\KnowledgeIndexRebuildResource;
use App\Models\Hotel;
use App\Models\KnowledgeIndexRebuild;
use App\Services\Knowledge\KnowledgeRebuildService;
use Illuminate\Http\Request;

/**
 * Bulk re-indexing of knowledge. Super admin only, by the route file's
 * middleware: a rebuild of everything touches every tenant.
 */
class KnowledgeRebuildController extends Controller
{
    public function store(StartKnowledgeRebuildRequest $request, KnowledgeRebuildService $rebuilds)
    {
        $scope = KnowledgeRebuildScope::from($request->validated('scope'));
        $hotel = $scope === KnowledgeRebuildScope::HOTEL ? Hotel::findOrFail($request->validated('hotel_id')) : null;

        $rebuild = $rebuilds->start($scope, $hotel, $request->user());

        return apiResponse('Rebuild started.', 202, KnowledgeIndexRebuildResource::make($rebuild->fresh('requester')));
    }

    public function index(Request $request)
    {
        $page = KnowledgeIndexRebuild::with('requester')
            ->latest('started_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return apiResponse('Rebuilds fetched successfully.', 200, KnowledgeIndexRebuildResource::collection($page));
    }

    public function show(string $id)
    {
        return apiResponse('Rebuild fetched successfully.', 200, KnowledgeIndexRebuildResource::make(KnowledgeIndexRebuild::with('requester')->findOrFail($id)));
    }
}
