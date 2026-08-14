<?php

namespace App\Policies;

use App\Models\KnowledgeBaseArticle;
use App\Models\User;

class KnowledgeBaseArticlePolicy
{
    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $user->isAdmin() && $knowledgeBaseArticle->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $user->isAdmin() && $knowledgeBaseArticle->hotel_id === $user->hotel?->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $user->isAdmin() && $knowledgeBaseArticle->hotel_id === $user->hotel?->id;
    }
}
