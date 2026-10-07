<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;
use App\Support\Knowledge\TextNormalizer;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * XLSX and CSV. The first non-empty row of each sheet is its header row, and
 * every later row is written as "Header: value; Header: value", so a passage
 * holding row 37 still says which column each value belongs to.
 */
class SpreadsheetExtractor implements Extractor
{
    private const ROWS_PER_SEGMENT = 40;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, [self::XLSX, 'text/csv', 'application/csv'], true);
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        return $mimeType === self::XLSX
            ? $this->extractWorkbook($absolutePath)
            : $this->extractCsv($absolutePath);
    }

    /**
     * @param  list<?string>  $headers
     * @param  list<mixed>  $cells
     */
    public static function describeRow(array $headers, array $cells): string
    {
        $pairs = [];

        foreach ($cells as $index => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $header = trim((string) ($headers[$index] ?? ''));
            $pairs[] = $header === '' ? $value : "{$header}: {$value}";
        }

        return implode('; ', $pairs);
    }

    private function extractWorkbook(string $path): ExtractionResult
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, $e->getMessage(), $e);
        }

        $segments = [];
        $total = 0;

        foreach ($book->getWorksheetIterator() as $sheet) {
            $rows = $this->rows($sheet);
            $total += count($rows);
            $this->assertWithinLimit($total);

            $segments = [...$segments, ...$this->segmentRows($rows, 'Sheet '.$sheet->getTitle().', rows')];
        }

        return new ExtractionResult($segments, $book->getSheetCount());
    }

    private function extractCsv(string $path): ExtractionResult
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::FILE_MISSING, 'The CSV file could not be read.');
        }

        // Convert first, so the reader only ever sees UTF-8 (Arabic exports
        // from Excel are usually Windows-1256).
        $utf8 = TextNormalizer::toUtf8($raw);
        $temp = tempnam(sys_get_temp_dir(), 'kcsv');
        file_put_contents($temp, $utf8);

        try {
            $reader = new Csv;
            $reader->setInputEncoding('UTF-8');
            $reader->setDelimiter($this->sniffDelimiter($utf8));
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($temp)->getActiveSheet();
        } catch (Throwable $e) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::CORRUPT, $e->getMessage(), $e);
        } finally {
            @unlink($temp);
        }

        $rows = $this->rows($sheet);
        $this->assertWithinLimit(count($rows));

        return new ExtractionResult($this->segmentRows($rows, 'Rows'), 1);
    }

    /**
     * Non-empty rows keyed by their 1-based row number.
     *
     * @return array<int, list<mixed>>
     */
    private function rows(Worksheet $sheet): array
    {
        $rows = [];

        foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
            $cells = array_map(fn ($cell) => $cell === null ? '' : TextNormalizer::normalize((string) $cell), $cells);

            if (implode('', $cells) !== '') {
                $rows[$index + 1] = $cells;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, list<mixed>>  $rows
     * @return list<Segment>
     */
    private function segmentRows(array $rows, string $label): array
    {
        if ($rows === []) {
            return [];
        }

        // Not array_shift(): it would renumber the rows, and the numbers are
        // the citation.
        $headerRow = array_key_first($rows);
        $headers = $rows[$headerRow];
        unset($rows[$headerRow]);

        if ($rows === []) {
            $line = self::describeRow([], $headers);

            return $line === '' ? [] : [new Segment($label === 'Rows' ? 'Rows 1–1' : "{$label} 1–1", $line)];
        }

        $segments = [];

        foreach (array_chunk($rows, self::ROWS_PER_SEGMENT, true) as $chunk) {
            $numbers = array_keys($chunk);
            $lines = array_filter(array_map(fn (array $cells) => self::describeRow($headers, $cells), $chunk));

            if ($lines !== []) {
                $segments[] = new Segment("{$label} ".reset($numbers).'–'.end($numbers), implode("\n", $lines));
            }
        }

        return $segments;
    }

    private function sniffDelimiter(string $text): string
    {
        $sample = implode("\n", array_slice(explode("\n", $text), 0, 5));
        $counts = [];

        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $counts[$delimiter] = substr_count($sample, $delimiter);
        }

        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    private function assertWithinLimit(int $rows): void
    {
        if ($rows > (int) config('knowledge.max_rows')) {
            throw new ExtractionFailed(KnowledgeDocumentFailure::TOO_MANY_ROWS, "{$rows} rows exceed the limit.");
        }
    }
}
