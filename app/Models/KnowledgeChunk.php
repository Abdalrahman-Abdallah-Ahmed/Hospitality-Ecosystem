<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class KnowledgeChunk extends Model
{
    use HasUuids;

    protected $table = 'knowledge_chunks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'chunkable_type',
        'chunkable_id',
        'hotel_id',
        'category',
        'chunk_index',
        'content',
        'token_count',
        'metadata',
        'embedding',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'token_count' => 'integer',
        'metadata' => 'array',
        'embedding' => 'array',
    ];

    public function chunkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
