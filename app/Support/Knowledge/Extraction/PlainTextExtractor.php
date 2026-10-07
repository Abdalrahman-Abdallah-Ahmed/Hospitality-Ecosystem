<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;
use App\Support\Knowledge\TextNormalizer;

/**
 * Plain text is one segment; Markdown gets one segment per heading, so a
 * citation can name the section.
 */
class PlainTextExtractor implements Extractor
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['text/plain', 'text/markdown'], true);
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        $raw = @file_get_contents($absolutePath);

        if ($raw === false) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::FILE_MISSING, 'The text file could not be read.');
        }

        $text = TextNormalizer::normalize($raw);

        if ($mimeType === 'text/markdown' || preg_match('/^#{1,3} \S/m', $text)) {
            $segments = $this->markdownSections($text);
        } else {
            $segments = $text === '' ? [] : [new Segment('Document', $text)];
        }

        return new ExtractionResult($segments, 1);
    }

    /**
     * @return list<Segment>
     */
    private function markdownSections(string $text): array
    {
        $segments = [];
        $heading = null;
        $buffer = [];

        $flush = function () use (&$segments, &$heading, &$buffer) {
            $body = trim(implode("\n", $buffer));

            if ($body !== '') {
                $segments[] = new Segment($heading === null ? 'Document' : "Section: {$heading}", $body);
            }

            $buffer = [];
        };

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $match)) {
                $flush();
                $heading = trim($match[1]);

                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $segments;
    }
}
