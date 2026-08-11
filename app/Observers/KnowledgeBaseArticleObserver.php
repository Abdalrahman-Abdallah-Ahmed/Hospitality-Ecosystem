<?php

namespace App\Observers;

use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\KnowledgeBaseArticle;

class KnowledgeBaseArticleObserver
{
    /**
     * Handle the KnowledgeBaseArticle "saved" event.
     */
    public function saved(KnowledgeBaseArticle $article): void
    {
        if ($article->status !== 'published') {
            $article->chunks()->delete();

            return;
        }

        // isDirty(), not wasChanged(): wasChanged() is stale on a no-op save (Eloquent only
        // refreshes it when an UPDATE actually runs), which would wrongly look like a change.
        if (! $article->isDirty(['content', 'title', 'category', 'tags', 'status'])) {
            return;
        }

        SyncKnowledgeChunksJob::dispatch($article);
    }

    /**
     * Handle the KnowledgeBaseArticle "deleted" event.
     */
    public function deleted(KnowledgeBaseArticle $article): void
    {
        $article->chunks()->delete();
    }
}
