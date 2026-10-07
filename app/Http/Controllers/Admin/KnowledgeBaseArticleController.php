<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Generic\GenericStoreRequest;
use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Http\Resources\KnowledgeBaseArticleResource;
use App\Models\KnowledgeBaseArticle;
use App\Support\RequestRules\GenericQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global knowledge base articles (hotel_id IS NULL), including those created
 * through the hotel routes before global knowledge had a home of its own.
 * Guarded by the super_admin middleware on the whole admin route file.
 */
class KnowledgeBaseArticleController extends Controller
{
    public function index(GenericIndexRequest $request)
    {
        $articles = GenericQuery::apply($this->globalArticles(), $request);

        return apiResponse('Global articles fetched successfully.', 200, KnowledgeBaseArticleResource::collection($articles));
    }

    public function store(GenericStoreRequest $request)
    {
        $validated = unsetAttributes($request->validated(), ['hotel_id']);

        // A global article must stay global, whatever tenant context is set.
        $previous = TenantContext::currentHotelId();
        TenantContext::setCurrentHotelId(null);

        try {
            $article = KnowledgeBaseArticle::create([...$validated, 'hotel_id' => null]);
        } finally {
            TenantContext::setCurrentHotelId($previous);
        }

        return apiResponse('Global article created successfully.', 201, KnowledgeBaseArticleResource::make($article));
    }

    public function show(string $id)
    {
        return apiResponse('Global article fetched successfully.', 200, KnowledgeBaseArticleResource::make($this->globalArticles()->findOrFail($id)));
    }

    public function update(GenericUpdateRequest $request, string $id)
    {
        $article = $this->globalArticles()->findOrFail($id);
        $article->update(unsetAttributes($request->validated(), ['hotel_id']));

        return apiResponse('Global article updated successfully.', 200, KnowledgeBaseArticleResource::make($article->refresh()));
    }

    public function destroy(string $id)
    {
        $this->globalArticles()->findOrFail($id)->delete();

        return apiResponse('Global article deleted successfully.', 200);
    }

    private function globalArticles(): Builder
    {
        return KnowledgeBaseArticle::withoutGlobalScope('hotel')->whereNull('hotel_id');
    }
}
