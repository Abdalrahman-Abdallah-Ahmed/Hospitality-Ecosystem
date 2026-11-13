<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\KnowledgeBaseArticleResource;
use App\Models\KnowledgeBaseArticle;
use App\Services\Knowledge\ArticleCommands;
use App\Support\RequestRules\GenericQuery;

class KnowledgeBaseArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', KnowledgeBaseArticle::class);

        // These are hotel routes: always the user's hotels' articles, by the
        // tenant scope. The global knowledge base is managed from
        // /api/superadmin/knowledge-base-articles.
        $articles = GenericQuery::apply(KnowledgeBaseArticle::with(['hotel']), $request);

        return apiResponse('Articles fetched successfully.', 200, KnowledgeBaseArticleResource::collection($articles));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', KnowledgeBaseArticle::class);
        $user = $request->user();

        // Every article created here belongs to the hotel the request works
        // in. Global articles are created only from
        // /api/superadmin/knowledge-base-articles.
        $hotel = resolveHotel($user);

        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $article = app(ArticleCommands::class)->create($hotel, unsetAttributes($request->validated(), ['hotel_id']));

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

        app(ArticleCommands::class)->update($knowledgeBaseArticle, unsetAttributes($request->validated(), ['hotel_id']));

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
     * Global articles are only reachable from /api/superadmin/knowledge-base-articles.
     * The tenant scope already leaves them out here; this keeps the door shut
     * if that ever changes.
     */
    private function rejectGlobal(KnowledgeBaseArticle $article): void
    {
        abort_if($article->hotel_id === null, 404);
    }
}
