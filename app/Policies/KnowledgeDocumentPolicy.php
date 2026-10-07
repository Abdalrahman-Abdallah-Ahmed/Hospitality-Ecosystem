<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Hotel knowledge documents. Global documents (hotel_id = null) are managed
 * only from routes/admin.php, which the super_admin middleware guards, so
 * every ability here refuses them, whoever is asking.
 */
class KnowledgeDocumentPolicy
{
    use ChecksPermissions;

    /**
     * Global records are refused outright. A super admin is otherwise let
     * through: allows() compares the record's hotel with the user's own, and a
     * super admin has none, while the controller has already pinned them to
     * the hotel they named.
     */
    public function before(User $user, string $ability, mixed $document = null): ?bool
    {
        if ($document instanceof KnowledgeDocument && $document->isGlobal()) {
            return false;
        }

        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_VIEW);
    }

    public function view(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_VIEW, $document);
    }

    public function download(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_VIEW, $document);
    }

    public function viewText(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_VIEW, $document);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_CREATE);
    }

    public function update(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_UPDATE, $document);
    }

    public function replace(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_UPDATE, $document);
    }

    public function correctText(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_UPDATE, $document);
    }

    public function discardText(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_UPDATE, $document);
    }

    public function delete(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_DELETE, $document);
    }

    public function viewDeleted(User $user): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_DELETE);
    }

    public function restore(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_DELETE, $document);
    }

    public function reindex(User $user, KnowledgeDocument $document): bool
    {
        return $this->allows($user, Permission::KNOWLEDGE_DOCUMENTS_REINDEX, $document);
    }
}
