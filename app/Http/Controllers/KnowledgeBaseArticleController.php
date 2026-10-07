<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\KnowledgeBaseArticleResource;
use App\Models\KnowledgeBaseArticle;
use App\Support\RequestRules\GenericQuery;

class KnowledgeBaseArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', KnowledgeBaseArticle::class);

        // These are hotel routes: always one hotel's articles. A super admin
        // names the hotel; the global knowledge base is managed from
        // /api/admin/knowledge-base-articles.
        $query = KnowledgeBaseArticle::with(['hotel']);

        if ($request->user()->isSuperAdmin()) {
            if (! $request->filled('hotel_id')) {
                return apiResponse('A hotel_id is required.', 422);
            }

            $query->where('hotel_id', $request->input('hotel_id'));
        }

        $articles = GenericQuery::apply($query, $request);

        return apiResponse('Articles fetched successfully.', 200, KnowledgeBaseArticleResource::collection($articles));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', KnowledgeBaseArticle::class);
        $user = $request->user();

        // Every article created here belongs to a hotel: a scoped user's own,
        // or the one a super admin names. Global articles are created only
        // from /api/admin/knowledge-base-articles.
        if ($user->isSuperAdmin() && ! $request->filled('hotel_id')) {
            return apiResponse('A hotel_id is required.', 422);
        }

        $hotel = resolveHotel($user, $request->input('hotel_id'));

        if (! $hotel) {
            return $user->isSuperAdmin()
                ? apiResponse('Hotel not found.', 404)
                : apiResponse('You do not belong to any hotel.', 403);
        }

        $article = KnowledgeBaseArticle::create([
            ...unsetAttributes($request->validated(), ['hotel_id']),
            'hotel_id' => $hotel->id,
        ]);

        return apiResponse('Article created successfully.', 201, KnowledgeBaseArticleResource::make($article->load(['hotel'])));
    }

    /**
     * Display the specified resource.
     */
    public function show(KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->rejectGlobal($knowledgeBaseArticle);
        $this->authorize('view', $knowledgeBaseArticle);

        $knowledgeBaseArticle->load('hotel');

        return apiResponse('Article fetched successfully.', 200, KnowledgeBaseArticleResource::make($knowledgeBaseArticle));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->rejectGlobal($knowledgeBaseArticle);
        $this->authorize('update', $knowledgeBaseArticle);
        $validated = unsetAttributes($request->validated(), ['hotel_id']);
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            $hotel = $user->hotel;
            if (! $hotel) {
                return apiResponse('You do not belong to any hotel.', 403);
            }
            $validated['hotel_id'] = $hotel->id;
        }

        $knowledgeBaseArticle->update($validated);

        return apiResponse('Article updated successfully.', 200, KnowledgeBaseArticleResource::make($knowledgeBaseArticle->load(['hotel'])));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->rejectGlobal($knowledgeBaseArticle);
        $this->authorize('delete', $knowledgeBaseArticle);
        $knowledgeBaseArticle->delete();

        return apiResponse('Article deleted successfully.', 200);
    }

    /**
     * Global articles are only reachable from /api/admin/knowledge-base-articles.
     * Scoped users never see them here anyway (the tenant scope); this closes
     * the same door for an unrestricted super admin.
     */
    private function rejectGlobal(KnowledgeBaseArticle $article): void
    {
        abort_if($article->hotel_id === null, 404);
    }
}
