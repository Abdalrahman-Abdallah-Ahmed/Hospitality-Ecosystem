<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;

/**
 * Picks the extractor for a detected MIME type. allowedMimeTypes() is the
 * single allow-list upload validation uses, so what can be uploaded and what
 * can be read never drift apart.
 */
class ExtractorRegistry
{
    /** @var array<string, class-string<Extractor>> */
    private const EXTRACTORS = [
        'application/pdf' => PdfExtractor::class,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => DocxExtractor::class,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => SpreadsheetExtractor::class,
        'text/csv' => SpreadsheetExtractor::class,
        'text/plain' => PlainTextExtractor::class,
        'text/markdown' => PlainTextExtractor::class,
        'image/jpeg' => VisionExtractor::class,
        'image/png' => VisionExtractor::class,
        'image/webp' => VisionExtractor::class,
    ];

    /** @var array<string, string> extension => the MIME type documents are stored under */
    public const EXTENSIONS = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function for(string $mimeType): Extractor
    {
        $class = self::EXTRACTORS[$mimeType] ?? null;

        if ($class === null) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::UNSUPPORTED_TYPE, "No extractor for {$mimeType}.");
        }

        return app($class);
    }

    public static function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return array_keys(self::EXTRACTORS);
    }
}
