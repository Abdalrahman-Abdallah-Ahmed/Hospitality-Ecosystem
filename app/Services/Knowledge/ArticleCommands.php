<?php

namespace App\Services\Knowledge;

use App\Exceptions\DomainRuleException;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;

/**
 * Writing a hotel's knowledge base articles, for the staff API and the Admin
 * AI alike (SPEC-055 R10). Articles written here always belong to a hotel:
 * global articles are managed only from /api/superadmin. Indexing follows from the
 * article observer, exactly as for any other save.
 */
class ArticleCommands
{
    /**
     * @param  array<string, mixed>  $attributes  validated, without hotel_id
     */
    public function create(Hotel $hotel, array $attributes): KnowledgeBaseArticle
    {
        return KnowledgeBaseArticle::create([...$attributes, 'hotel_id' => $hotel->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes  validated, without hotel_id
     *
     * @throws DomainRuleException for a global article, which is not managed here
     */
    public function update(KnowledgeBaseArticle $article, array $attributes): KnowledgeBaseArticle
    {
        if ($article->hotel_id === null) {
            throw new DomainRuleException('Article not found.', 404);
        }

        $article->update($attributes);

        return $article;
    }
}
