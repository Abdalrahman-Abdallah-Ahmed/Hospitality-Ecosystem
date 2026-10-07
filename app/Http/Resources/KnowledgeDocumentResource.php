<?php

namespace App\Http\Resources;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Storage paths, disks and hashes never leave the server. The internal error
 * detail is shown only to admins, under `debug`.
 *
 * @mixin KnowledgeDocument
 */
class KnowledgeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'title' => $this->title,
            'category' => $this->category?->value,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'status' => $this->status?->value,
            'failure_code' => $this->failure_code?->value,
            'failure_message' => $this->failure_code?->message(app()->getLocale()),
            'is_active' => $this->is_active,
            'page_count' => $this->page_count,
            'scanned_page_count' => $this->scanned_page_count,
            'chunk_count' => $this->chunk_count,
            'content_source' => $this->content_source?->value,
            'corrected_at' => $this->corrected_at,
            'corrected_by' => $this->corrector ? ['id' => $this->corrector->id, 'name' => $this->corrector->name] : null,
            'has_pending_replacement' => $this->hasPendingReplacement(),
            'uploaded_by' => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null,
            'indexed_at' => $this->indexed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'restorable_until' => $this->when($this->trashed(), fn () => $this->restorableUntil()),
            'debug' => $this->when(
                $user !== null && ($user->isAdmin() || $user->isSuperAdmin()) && $this->error !== null,
                fn () => ['error' => $this->error],
            ),
        ];
    }
}
