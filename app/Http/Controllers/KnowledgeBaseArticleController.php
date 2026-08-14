<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Models\KnowledgeBaseArticle;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\Request;

class KnowledgeBaseArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', KnowledgeBaseArticle::class);

        $articles = GenericQuery::apply(
            KnowledgeBaseArticle::with(['hotel'])
                ->where('hotel_id', $request->user()->hotel?->id),
            $request
        );

        return apiResponse('Articles fetched successfully.', 200, $articles);        
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(GenericStoreRequest $request)
    {
        $this->authorize('create', KnowledgeBaseArticle::class);
        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $article = KnowledgeBaseArticle::create([
            ...$validated,
            'hotel_id' => $hotel->id,
        ]);

        return apiResponse('Article created successfully.', 201, $article->load(['hotel']));
    }

    /**
     * Display the specified resource.
     */
    public function show(KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->authorize('view', $knowledgeBaseArticle);

        $knowledgeBaseArticle->load('hotel');
        return apiResponse('Article fetched successfully.', 200, $knowledgeBaseArticle);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(GenericUpdateRequest $request, KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->authorize('update', $knowledgeBaseArticle);
        $validated = unsetAttributes($request->validated(), ['hotel_id']);
        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $knowledgeBaseArticle->update([...$validated, 'hotel_id' => $hotel->id]);
        return apiResponse('Article updated successfully.', 200, $knowledgeBaseArticle->load(['hotel']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(KnowledgeBaseArticle $knowledgeBaseArticle)
    {
        $this->authorize('delete', $knowledgeBaseArticle);
        $knowledgeBaseArticle->delete();
        return apiResponse('Article deleted successfully.', 200);
    }
}
