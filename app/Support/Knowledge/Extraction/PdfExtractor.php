<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;
use App\Support\Knowledge\TextNormalizer;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a PDF's text layer page by page. A page with almost no text is
 * reported as scanned (an image of text), for AI vision to read; it is never
 * sent anywhere from here.
 */
class PdfExtractor implements Extractor
{
    /** Fewer visible characters than this and the page is treated as an image. */
    private const SCANNED_PAGE_MAX_CHARS = 20;

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        try {
            $document = (new Parser)->parseFile($absolutePath);
            $pages = $document->getPages();
        } catch (Throwable $e) {
            throw new ExtractionFailed(
                str_contains(strtolower($e->getMessage()), 'secured') || str_contains(strtolower($e->getMessage()), 'encrypt')
                    ? KnowledgeDocumentFailure::ENCRYPTED
                    : KnowledgeDocumentFailure::CORRUPT,
                $e->getMessage(),
                $e,
            );
        }

        $segments = [];
        $scanned = [];

        foreach (array_values($pages) as $index => $page) {
            $number = $index + 1;

            // A page whose text layer the parser cannot decode is treated like
            // a page with none: it goes to AI vision instead of failing the
            // whole document over one bad font table.
            try {
                $text = TextNormalizer::normalize($page->getText());
            } catch (Throwable) {
                $text = '';
            }

            if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < self::SCANNED_PAGE_MAX_CHARS) {
                $scanned[] = $number;

                continue;
            }

            $segments[] = new Segment("Page {$number}", $text);
        }

        return new ExtractionResult($segments, count($pages), $scanned);
    }
}
