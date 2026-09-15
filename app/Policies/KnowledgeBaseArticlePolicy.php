<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KnowledgeBaseArticle;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class KnowledgeBaseArticlePolicy
{
    use ChecksPermissions;

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
        return $this->allows($user, Permission::KNOWLEDGE_BASE_ARTICLES_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_BASE_ARTICLES_VIEW, $knowledgeBaseArticle);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_BASE_ARTICLES_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_BASE_ARTICLES_UPDATE, $knowledgeBaseArticle);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeBaseArticle $knowledgeBaseArticle): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_BASE_ARTICLES_DELETE, $knowledgeBaseArticle);
    }
}
