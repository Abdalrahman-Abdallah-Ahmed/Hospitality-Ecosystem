<?php

namespace App\Jobs;

use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\KnowledgeContentSource;
use App\Enums\KnowledgeDocumentFailure;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\MeterFeature;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeIndexRebuild;
use App\Services\Knowledge\KnowledgeDocumentService;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use App\Support\Audit\EventLogger;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Knowledge\Extraction\ExtractionFailed;
use App\Support\Knowledge\Extraction\ExtractorRegistry;
use App\Support\Knowledge\Extraction\Segment;
use App\Support\Knowledge\Extraction\VisionExtractor;
use App\Support\Knowledge\KnowledgeEmbeddingFailed;
use App\Support\Knowledge\TextNormalizer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns one knowledge document into searchable passages:
 * detect → extract (or read the stored segments) → normalize → chunk →
 * embed → atomic swap.
 *
 * What it guarantees:
 *  - the stored file is only ever read;
 *  - the live passages are replaced all at once or not at all, so a failure
 *    at any step leaves the previous version searchable;
 *  - a run whose input changed while it worked (a replacement, a correction)
 *    discards its result: the newer run owns the document;
 *  - permanent failures (an encrypted file, too many scanned pages…) are
 *    recorded and not retried; transient ones are retried three times.
 */
class IndexKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public const MODE_EXTRACT = 'extract';

    public const MODE_FROM_SEGMENTS = 'from_segments';

    /**
     * Retries are counted in exceptions, not attempts: a run released
     * because another run of the same document holds the lock must not use
     * up a retry, or four overlaps would mark a healthy document failed.
     */
    public int $tries = 0;

    public int $maxExceptions = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /** Extraction, vision and embedding together can take minutes. */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private bool $reported = false;

    public function __construct(
        public string $documentId,
        public string $mode = self::MODE_EXTRACT,
        public ?string $rebuildId = null,
    ) {}

    public function retryUntil(): CarbonInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->documentId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(ExtractorRegistry $registry, VisionExtractor $vision, MeteringService $metering): void
    {
        $document = KnowledgeDocument::withoutGlobalScope('hotel')->find($this->documentId);

        if ($document === null) {
            // Deleted (or purged) since it was queued: nothing to index.
            $this->reportRebuild(true);

            return;
        }

        $run = fn () => $this->index($document, $registry, $vision, $metering);

        $document->hotel_id === null
            ? TenantContext::withoutScope($run)
            : TenantContext::runForHotel($document->hotel_id, $run);
    }

    /**
     * Runs on a fresh copy of the job once retries are spent, so nothing set
     * during handle() survives: the stage comes from the exception's type.
     */
    public function failed(?Throwable $exception): void
    {
        $document = KnowledgeDocument::withoutGlobalScope('hotel')->find($this->documentId);
        $failure = $exception instanceof KnowledgeEmbeddingFailed
            ? KnowledgeDocumentFailure::EMBEDDING_UNAVAILABLE
            : KnowledgeDocumentFailure::EXTRACTION_UNAVAILABLE;

        // Only a document still in the pipeline is this run's to fail. One
        // that is indexed was finished by a newer run after this run's last
        // attempt began; one already failed permanently keeps that reason.
        if ($document !== null && in_array($document->status, [KnowledgeDocumentStatus::UPLOADED, KnowledgeDocumentStatus::EXTRACTING], true)) {
            $this->markFailed($document, $failure, $exception?->getMessage() ?? 'The indexing job failed.');
        }

        $this->reportRebuild(false, $document, $failure->value);
    }

    private function index(KnowledgeDocument $document, ExtractorRegistry $registry, VisionExtractor $vision, MeteringService $metering): void
    {
        $fromPending = $document->hasPendingReplacement() && $this->mode === self::MODE_EXTRACT;
        $fromStored = $this->mode === self::MODE_FROM_SEGMENTS && $document->segments !== null;

        // A plain extract of a corrected document is a "discard corrections"
        // run that a newer correction has overtaken: the correction wins, and
        // its own run indexes it.
        if ($this->mode === self::MODE_EXTRACT && ! $fromPending && $document->isCorrected()) {
            $this->reportRebuild(true);

            return;
        }

        $document->forceFill([
            'status' => KnowledgeDocumentStatus::EXTRACTING,
            'failure_code' => null,
        ])->save();

        $fingerprint = $document->inputFingerprint();

        try {
            // Opened before extraction, so AI vision is attributed to the
            // hotel and checked against its spend ceiling like the embedding.
            $outcome = AiCostContext::for(
                kind: AiTriggerKind::SCHEDULED_JOB,
                hotel: $document->hotel,
                trigger: $document,
                callback: fn () => $this->extractAndSwap($document, $registry, $vision, $fromPending, $fromStored, $fingerprint),
            );
        } catch (ExtractionFailed $e) {
            if (! $e->failure->isPermanent()) {
                throw $e;
            }

            $this->failPermanently($document, $e->failure, $e->getMessage(), $fromPending);

            return;
        } catch (AiSpendCeilingExceededException $e) {
            $this->failPermanently($document, KnowledgeDocumentFailure::USAGE_LIMIT_REACHED, $e->getMessage(), false);

            return;
        } catch (UniqueConstraintViolationException $e) {
            // The replacement's hash was taken by another upload while this
            // ran. The swap rolled back, so the live version is untouched.
            $this->failPermanently($document, KnowledgeDocumentFailure::DUPLICATE, $e->getMessage(), $fromPending);

            return;
        }

        [$count, $scannedPages] = $outcome;

        if ($count === null) {
            // Superseded by a newer input, or deleted mid-run. The newer run
            // (if any) owns the document now.
            $this->reportRebuild(true);

            return;
        }

        if ($document->hotel !== null) {
            $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                hotel: $document->hotel,
                feature: MeterFeature::EMBEDDINGS_GENERATED,
                quantity: $count,
                source: $document,
                actorKind: ActorKind::SYSTEM,
            ));

            if ($scannedPages > 0) {
                $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                    hotel: $document->hotel,
                    feature: MeterFeature::KNOWLEDGE_PAGES_READ,
                    quantity: $scannedPages,
                    source: $document,
                    actorKind: ActorKind::SYSTEM,
                ));
            }
        }

        $this->reportRebuild(true);
    }

    /**
     * Read the text (stored segments, or the file), then embed and swap.
     * Returns [chunk count or null when the run was superseded, pages read
     * by AI vision].
     *
     * @return array{0: ?int, 1: int}
     */
    private function extractAndSwap(KnowledgeDocument $document, ExtractorRegistry $registry, VisionExtractor $vision, bool $fromPending, bool $fromStored, string $fingerprint): array
    {
        [$segments, $pageCount, $scannedPages] = $fromStored
            ? $this->storedSegments($document)
            : $this->extract($document, $registry, $vision, $fromPending);

        $indexable = array_values(array_filter($segments, fn (Segment $segment) => trim($segment->text) !== ''));

        if ($indexable === []) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::NO_TEXT, 'No readable text was found.');
        }

        $oldPath = $document->path;

        try {
            $count = ChunkSynchronizer::syncSegments(
                source: $document,
                segments: $indexable,
                hotelId: $document->hotel_id,
                category: $document->category?->value,
                metadata: ['title' => $document->title],
                guard: fn (KnowledgeDocument $locked) => ! $locked->trashed() && $locked->inputFingerprint() === $fingerprint,
                afterSwap: fn (KnowledgeDocument $locked, int $chunks) => $this->recordSwap($locked, $segments, $pageCount, $scannedPages, $chunks, $fromPending, $oldPath),
            );
        } catch (UniqueConstraintViolationException|AiSpendCeilingExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Marks the stage for failed(), which runs on a fresh copy of
            // the job and cannot see anything set here.
            throw new KnowledgeEmbeddingFailed($e);
        }

        return [$count, $scannedPages];
    }

    /**
     * A failure retrying cannot fix. A replacement file that fails this way
     * is discarded, so later re-indexes, corrections and rebuilds go back to
     * the live version instead of hitting the same broken file again.
     */
    private function failPermanently(KnowledgeDocument $document, KnowledgeDocumentFailure $failure, string $detail, bool $discardPending): void
    {
        if ($discardPending) {
            $this->discardPendingFile($document);
        }

        $this->markFailed($document, $failure, $detail);
        $this->reportRebuild(false, $document, $failure->value);
    }

    private function discardPendingFile(KnowledgeDocument $document): void
    {
        $fresh = KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->find($document->id);

        if ($fresh?->pending_path === null) {
            return;
        }

        $disk = $fresh->disk;
        $path = $fresh->pending_path;

        $fresh->forceFill([
            'pending_path' => null,
            'pending_original_filename' => null,
            'pending_mime_type' => null,
            'pending_size' => null,
            'pending_content_hash' => null,
        ])->save();

        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }

    /**
     * @return array{0: list<Segment>, 1: ?int, 2: int}
     */
    private function storedSegments(KnowledgeDocument $document): array
    {
        return [
            array_map(fn (array $segment) => Segment::fromArray($segment), $document->segments),
            $document->page_count,
            0,
        ];
    }

    /**
     * @return array{0: list<Segment>, 1: ?int, 2: int}
     */
    private function extract(KnowledgeDocument $document, ExtractorRegistry $registry, VisionExtractor $vision, bool $fromPending): array
    {
        $disk = $document->disk;
        $path = $fromPending ? $document->pending_path : $document->path;
        $mimeType = $fromPending ? $document->pending_mime_type : $document->mime_type;

        if ($path === null || ! Storage::disk($disk)->exists($path)) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::FILE_MISSING, "Stored file {$path} is missing.");
        }

        $extractor = $registry->for($mimeType);

        // Extractors read a local copy; the stored file is never opened for
        // writing, wherever the disk is.
        $temp = tempnam(sys_get_temp_dir(), 'kdoc');
        file_put_contents($temp, Storage::disk($disk)->get($path));

        try {
            $result = $extractor->extract($temp, $mimeType);
        } finally {
            @unlink($temp);
        }

        $segments = TextNormalizer::stripRepeatedEdges($result->segments);

        if ($result->scannedPageNumbers !== []) {
            $read = $vision->transcribe($disk, $path, $mimeType, $result->scannedPageNumbers);
            $segments = $this->mergeByPage([...$segments, ...$read]);
        }

        return [$segments, $result->pageCount, $result->scannedPageCount()];
    }

    /**
     * Text-layer pages and vision pages back in page order.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    private function mergeByPage(array $segments): array
    {
        usort($segments, function (Segment $a, Segment $b) {
            $page = fn (Segment $s) => preg_match('/^Page (\d+)$/', (string) $s->location, $m) ? (int) $m[1] : PHP_INT_MAX;

            return $page($a) <=> $page($b);
        });

        return $segments;
    }

    /**
     * Runs inside the swap transaction, on the locked row: the document's
     * bookkeeping commits with its passages or not at all.
     *
     * @param  list<Segment>  $segments
     */
    private function recordSwap(KnowledgeDocument $locked, array $segments, ?int $pageCount, int $scannedPages, int $chunks, bool $fromPending, string $oldPath): void
    {
        $stored = array_map(fn (Segment $segment) => $segment->toArray(), $segments);

        $attributes = [
            'segments' => $stored,
            'content' => KnowledgeDocumentService::joinSegments($stored),
            'status' => KnowledgeDocumentStatus::INDEXED,
            'failure_code' => null,
            'error' => null,
            'chunk_count' => $chunks,
            'page_count' => $pageCount,
            'scanned_page_count' => $scannedPages,
            'indexed_at' => now(),
        ];

        if ($fromPending) {
            $attributes += [
                'path' => $locked->pending_path,
                'original_filename' => $locked->pending_original_filename,
                'mime_type' => $locked->pending_mime_type,
                'size' => $locked->pending_size,
                'content_hash' => $locked->pending_content_hash,
                'pending_path' => null,
                'pending_original_filename' => null,
                'pending_mime_type' => null,
                'pending_size' => null,
                'pending_content_hash' => null,
                // A new file starts from its own extracted text.
                'content_source' => KnowledgeContentSource::EXTRACTED,
                'corrected_by' => null,
                'corrected_at' => null,
            ];
        }

        $locked->forceFill($attributes);
        $locked->forceFill(['index_fingerprint' => $locked->inputFingerprint()])->save();

        if ($fromPending) {
            EventLogger::record($locked, 'replaced');
            $disk = $locked->disk;
            DB::afterCommit(fn () => Storage::disk($disk)->delete($oldPath));
        }

        EventLogger::record($locked, 'indexed', ['chunk_count' => ['to' => $chunks]]);
    }

    private function markFailed(KnowledgeDocument $document, KnowledgeDocumentFailure $failure, string $detail): void
    {
        $fresh = KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->find($document->id);

        if ($fresh === null) {
            return;
        }

        $fresh->forceFill([
            'status' => KnowledgeDocumentStatus::FAILED,
            'failure_code' => $failure,
            'error' => mb_substr($detail, 0, 2000),
        ])->save();

        EventLogger::record($fresh, 'index_failed', null, $failure->value);
    }

    /**
     * Every exit path reports to the rebuild exactly once, or the rebuild
     * would never reach `completed`.
     */
    private function reportRebuild(bool $succeeded, ?KnowledgeDocument $document = null, ?string $reason = null): void
    {
        if ($this->reported || $this->rebuildId === null) {
            return;
        }

        $this->reported = true;

        KnowledgeIndexRebuild::find($this->rebuildId)?->recordOutcome(
            $succeeded,
            $succeeded ? null : [
                'source_type' => 'document',
                'source_id' => $this->documentId,
                'title' => $document?->title,
                'reason' => $reason ?? 'failed',
            ],
        );
    }
}
