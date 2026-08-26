<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\KnowledgeBaseCategory;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBaseArticle extends Model
{
    use BelongsToHotel, Filterable, HasUuids, SoftDeletes;

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

    public function chunks(): MorphMany
    {
        return $this->morphMany(KnowledgeChunk::class, 'chunkable');
    }
}
