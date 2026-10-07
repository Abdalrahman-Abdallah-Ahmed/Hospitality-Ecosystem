<?php

namespace App\Http\Resources;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document's indexed text, one segment per page, sheet or section, as
 * staff read and correct it.
 *
 * @mixin KnowledgeDocument
 */
class KnowledgeDocumentTextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_source' => $this->content_source?->value,
            'corrected_at' => $this->corrected_at,
            'corrected_by' => $this->corrector ? ['id' => $this->corrector->id, 'name' => $this->corrector->name] : null,
            'segments' => $this->segments ?? [],
        ];
    }
}
