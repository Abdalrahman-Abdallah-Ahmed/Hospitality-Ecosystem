<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\KnowledgeBaseCategory;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBaseArticle extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $table = 'knowledge_base_articles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'title',
        'category',
        'content',
        'tags',
        'status',
        'version',
    ];

    protected $casts = [
        'category' => KnowledgeBaseCategory::class,
        'tags' => 'array',
        'version' => 'integer',
    ];

    /**
     * Knowledge operations are audited (constitution, Knowledge and RAG).
     * Content is left out on purpose: it can be long, and the audit records
     * that an article changed, not a copy of it.
     */
    public function eventLoggedAttributes(): array
    {
        return ['title', 'category', 'tags', 'status', 'version'];
    }

    public function chunks(): MorphMany
    {
        return $this->morphMany(KnowledgeChunk::class, 'chunkable');
    }
}
