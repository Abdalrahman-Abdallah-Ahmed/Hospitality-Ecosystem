<?php

namespace App\Models;

use App\Enums\KnowledgeBaseCategory;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeBaseArticle extends Model
{
    use Filterable, HasUuids, SoftDeletes;

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

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
