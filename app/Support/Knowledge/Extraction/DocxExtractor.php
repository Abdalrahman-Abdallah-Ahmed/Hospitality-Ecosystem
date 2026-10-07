<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;
use App\Support\Knowledge\TextNormalizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * A DOCX is a zip of XML: paragraphs and tables in word/document.xml. Each
 * heading starts a new section, which becomes the citation location; table
 * rows are written out as "Header: value" so a row still reads correctly
 * once it is cut out of its table.
 */
class DocxExtractor implements Extractor
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, 'Not a readable zip archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, 'word/document.xml is missing.');
        }

        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, 'word/document.xml is not valid XML.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        $body = $xpath->query('/w:document/w:body')->item(0);

        if (! $body instanceof DOMElement) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, 'The document has no body.');
        }

        $sections = [];
        $current = 'Introduction';
        $buffer = [];

        foreach ($body->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'p') {
                $text = $this->paragraphText($xpath, $node);

                if ($text === '') {
                    continue;
                }

                if ($this->isHeading($xpath, $node)) {
                    $sections[] = [$current, $buffer];
                    $current = $text;
                    $buffer = [];

                    continue;
                }

                $buffer[] = $text;
            } elseif ($node->localName === 'tbl') {
                $buffer = [...$buffer, ...$this->tableRows($xpath, $node)];
            }
        }

        $sections[] = [$current, $buffer];

        $segments = [];

        foreach ($sections as [$heading, $lines]) {
            $text = TextNormalizer::normalize(implode("\n", $lines));

            if ($text !== '') {
                $segments[] = new Segment("Section: {$heading}", $text);
            }
        }

        return new ExtractionResult($segments, count($segments));
    }

    private function paragraphText(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $text = '';

        foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) as $node) {
            $text .= match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }

        return trim($text);
    }

    private function isHeading(DOMXPath $xpath, DOMElement $paragraph): bool
    {
        $style = $xpath->query('./w:pPr/w:pStyle', $paragraph)->item(0);

        if (! $style instanceof DOMElement) {
            return false;
        }

        $value = strtolower($style->getAttributeNS(self::W, 'val'));

        return str_starts_with($value, 'heading') || $value === 'title';
    }

    /**
     * @return list<string>
     */
    private function tableRows(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];

        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cells = [];

            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $parts = [];

                foreach ($xpath->query('./w:p', $cell) as $paragraph) {
                    $parts[] = $this->paragraphText($xpath, $paragraph);
                }

                $cells[] = trim(implode(' ', array_filter($parts)));
            }

            $rows[] = $cells;
        }

        if ($rows === []) {
            return [];
        }

        $headers = array_shift($rows);

        if ($rows === []) {
            return [implode('; ', array_filter($headers))];
        }

        return array_values(array_filter(array_map(
            fn (array $cells) => SpreadsheetExtractor::describeRow($headers, $cells),
            $rows,
        )));
    }
}
