<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\KnowledgeContentSource;
use App\Enums\KnowledgeDocumentFailure;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded file the assistants can answer from. `hotel_id = null` means
 * global: platform knowledge every hotel's assistant reads, managed only from
 * routes/admin.php.
 *
 * Searchable means: not deleted, `is_active`, and live passages exist.
 * `status` only describes the latest indexing run, which is why a document
 * being re-indexed or whose replacement failed is still searchable.
 */
class KnowledgeDocument extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $table = 'knowledge_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * ?search= matches titles only: the extracted text and storage columns
     * are not something a list search should reach into.
     *
     * @var list<string>
     */
    public array $searchable = ['title'];

    protected $fillable = [
        'hotel_id',
        'uploaded_by',
        'title',
        'category',
        'original_filename',
        'disk',
        'path',
        'mime_type',
        'size',
        'content_hash',
        'content',
        'segments',
        'content_source',
        'corrected_by',
        'corrected_at',
        'status',
        'failure_code',
        'error',
        'is_active',
        'page_count',
        'scanned_page_count',
        'chunk_count',
        'index_fingerprint',
        'indexed_at',
        'pending_path',
        'pending_original_filename',
        'pending_mime_type',
        'pending_size',
        'pending_content_hash',
    ];

    protected $casts = [
        'category' => KnowledgeBaseCategory::class,
        'status' => KnowledgeDocumentStatus::class,
        'failure_code' => KnowledgeDocumentFailure::class,
        'content_source' => KnowledgeContentSource::class,
        'segments' => 'array',
        'is_active' => 'boolean',
        'corrected_at' => 'datetime',
        'indexed_at' => 'datetime',
        'size' => 'integer',
        'pending_size' => 'integer',
        'page_count' => 'integer',
        'scanned_page_count' => 'integer',
        'chunk_count' => 'integer',
    ];

    protected $hidden = [
        'disk',
        'path',
        'pending_path',
        'content_hash',
        'pending_content_hash',
        'index_fingerprint',
    ];

    protected $attributes = [
        'status' => 'uploaded',
        'content_source' => 'extracted',
        'is_active' => true,
        'scanned_page_count' => 0,
        'chunk_count' => 0,
    ];

    protected static function booted(): void
    {
        // The table checks (status = 'failed') = (failure_code IS NOT NULL).
        // Callers clear the code themselves when they move a failed document
        // back into the pipeline; this is the safety net for one that forgets.
        static::saving(function (KnowledgeDocument $document): void {
            if ($document->status !== KnowledgeDocumentStatus::FAILED) {
                $document->failure_code = null;
            }
        });
    }

    public function eventLoggedAttributes(): array
    {
        return ['title', 'category', 'is_active', 'status', 'failure_code', 'content_source'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function chunks(): MorphMany
    {
        return $this->morphMany(KnowledgeChunk::class, 'chunkable');
    }

    public function isGlobal(): bool
    {
        return $this->hotel_id === null;
    }

    public function hasPendingReplacement(): bool
    {
        return $this->pending_path !== null;
    }

    public function isCorrected(): bool
    {
        return $this->content_source === KnowledgeContentSource::CORRECTED;
    }

    /**
     * What an indexing run is built from. A run captures this when it starts
     * and only swaps its passages in if it still matches at commit time, so a
     * replacement or a correction made mid-run always wins over the run that
     * started before it.
     */
    public function inputFingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->pending_content_hash ?? $this->content_hash ?? '',
            $this->content_source?->value ?? KnowledgeContentSource::EXTRACTED->value,
            $this->isCorrected() ? json_encode($this->segments) : '',
        ]));
    }

    public function restorableUntil(): ?CarbonInterface
    {
        return $this->deleted_at?->copy()->addDays((int) config('knowledge.purge_after_days'));
    }
}
