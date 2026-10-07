<?php

namespace App\Support\Knowledge\Extraction;

use App\Ai\Agents\DocumentVisionAgent;
use App\Enums\KnowledgeDocumentFailure;
use App\Support\Knowledge\TextNormalizer;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * Images, and the image-only pages of PDFs, read by AI vision. Both limits
 * are checked before anything is sent, so a document over them costs
 * nothing: no provider call, no usage, no meter event.
 */
class VisionExtractor implements Extractor
{
    public function supports(string $mimeType): bool
    {
        return ExtractorRegistry::isImage($mimeType);
    }

    /**
     * Extractor contract, for images: the whole file is one page. The job
     * calls transcribe() with the stored path instead, because the provider
     * attachment reads from the disk.
     */
    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        return new ExtractionResult([], 1, [1]);
    }

    /**
     * @param  list<int>  $pages
     * @return list<Segment>
     */
    public function transcribe(string $disk, string $path, string $mimeType, array $pages): array
    {
        if (count($pages) > (int) config('knowledge.vision.max_pages')) {
            throw new ExtractionFailed(
                KnowledgeDocumentFailure::TOO_MANY_SCANNED_PAGES,
                count($pages).' scanned pages exceed the limit of '.config('knowledge.vision.max_pages').'.',
            );
        }

        $size = Storage::disk($disk)->size($path);

        if ($size > (int) config('knowledge.vision.max_file_mb') * 1024 * 1024) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::TOO_LARGE_FOR_VISION, "{$size} bytes is too large to send for vision.");
        }

        $isImage = ExtractorRegistry::isImage($mimeType);
        $attachment = $isImage ? Image::fromStorage($path, $disk) : Document::fromStorage($path, $disk);

        $response = (new DocumentVisionAgent($pages))->prompt(
            'Transcribe the listed pages.',
            attachments: [$attachment],
            provider: config('knowledge.vision.provider'),
            model: config('knowledge.vision.model'),
            timeout: (int) config('knowledge.vision.timeout'),
        );

        $byPage = [];

        foreach ($response['pages'] ?? [] as $entry) {
            $number = (int) ($entry['page'] ?? 0);

            if (! in_array($number, $pages, true)) {
                continue;
            }

            $text = TextNormalizer::normalize(trim(($entry['text'] ?? '')."\n\n".($entry['description'] ?? '')));
            $hasText = TextNormalizer::normalize((string) ($entry['text'] ?? '')) !== '';

            if ($hasText) {
                $byPage[$number] = new Segment($isImage ? 'Image' : "Page {$number}", $text);
            }
        }

        ksort($byPage);

        return array_values($byPage);
    }
}
