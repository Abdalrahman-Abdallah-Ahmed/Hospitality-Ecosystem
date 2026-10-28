<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherLanguage;
use Mpdf\Mpdf;

/**
 * Fits text into a template box before rendering (research R5, FR-020/021),
 * measuring with the PDF engine's own font metrics so the result is
 * deterministic and testable without reading the PDF back.
 *
 * - shrink: wrap, then step the size down by 0.5 pt until it fits or reaches
 *   the minimum; then cut the last line with "…".
 * - truncate: keep the size, wrap, and cut the last line that fits with "…".
 * - lists: one item per line, shrinking the same way; at the minimum, keep
 *   as many items as fit and end with "+N more".
 */
class VoucherTextFitter
{
    /** Line height as a multiple of the font size; the views use the same. */
    public const LINE_HEIGHT = 1.25;

    private const PT_TO_MM = 0.352778;

    private const ELLIPSIS = '…';

    public function __construct(private Mpdf $mpdf) {}

    /**
     * @return array{size: float, lines: list<string>}
     */
    public function fitText(string $font, bool $bold, float $sizePt, float $widthMm, float $heightMm, string $text, string $overflow): array
    {
        $minimum = $this->minimum($sizePt);
        $size = $sizePt;

        if ($overflow === 'shrink') {
            while (true) {
                $lines = $this->wrap($font, $bold, $size, $widthMm, $text);

                if (count($lines) <= $this->maxLines($size, $heightMm) || $size <= $minimum) {
                    break;
                }

                $size = max($minimum, $size - 0.5);
            }
        }

        $lines = $this->wrap($font, $bold, $size, $widthMm, $text);

        return ['size' => $size, 'lines' => $this->cut($font, $bold, $size, $widthMm, $lines, $this->maxLines($size, $heightMm))];
    }

    /**
     * @param  list<string>  $items
     * @return array{size: float, lines: list<string>}
     */
    public function fitList(string $font, bool $bold, float $sizePt, float $widthMm, float $heightMm, array $items, VoucherLanguage $language): array
    {
        $minimum = $this->minimum($sizePt);
        $size = $sizePt;

        while (true) {
            $lines = $this->listLines($font, $bold, $size, $widthMm, $items);

            if (count($lines) <= $this->maxLines($size, $heightMm)) {
                return ['size' => $size, 'lines' => $lines];
            }

            if ($size <= $minimum) {
                break;
            }

            $size = max($minimum, $size - 0.5);
        }

        // At the minimum and still too long: keep the first k items and a
        // "+N more" line, with k as large as fits.
        $capacity = max(1, $this->maxLines($size, $heightMm));

        for ($keep = count($items) - 1; $keep >= 0; $keep--) {
            $lines = $this->listLines($font, $bold, $size, $widthMm, array_slice($items, 0, $keep));
            $lines[] = __('voucher.more', ['count' => count($items) - $keep], $language->value);

            if (count($lines) <= $capacity || $keep === 0) {
                return ['size' => $size, 'lines' => array_slice($lines, -$capacity)];
            }
        }

        return ['size' => $size, 'lines' => []];
    }

    public function maxLines(float $sizePt, float $heightMm): int
    {
        return (int) floor(($heightMm + 0.01) / ($sizePt * self::LINE_HEIGHT * self::PT_TO_MM));
    }

    /**
     * Each item on its own line, wrapped to the width; an item never spills
     * into the next one's line.
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private function listLines(string $font, bool $bold, float $size, float $widthMm, array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            array_push($lines, ...$this->wrap($font, $bold, $size, $widthMm, $item));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $font, bool $bold, float $size, float $widthMm, string $text): array
    {
        $this->mpdf->SetFont($font, $bold ? 'B' : '', $size);

        $lines = [];

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $paragraph) {
            $line = '';

            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;

                if ($this->width($candidate) <= $widthMm) {
                    $line = $candidate;

                    continue;
                }

                if ($line !== '') {
                    $lines[] = $line;
                }

                // A single word wider than the box is broken by characters.
                $line = '';
                foreach (mb_str_split($word) as $char) {
                    if ($line !== '' && $this->width($line.$char) > $widthMm) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function cut(string $font, bool $bold, float $size, float $widthMm, array $lines, int $maxLines): array
    {
        if (count($lines) <= $maxLines) {
            return $lines;
        }

        if ($maxLines < 1) {
            return [];
        }

        $this->mpdf->SetFont($font, $bold ? 'B' : '', $size);
        $kept = array_slice($lines, 0, $maxLines);
        $last = array_pop($kept);

        while ($last !== '' && $this->width($last.self::ELLIPSIS) > $widthMm) {
            $last = mb_substr($last, 0, -1);
        }

        $kept[] = rtrim($last).self::ELLIPSIS;

        return $kept;
    }

    /**
     * Measured before shaping. For Arabic this overestimates (unjoined
     * letters are wider, by about 20% in XB Riyaz), so Arabic text may come
     * out a little smaller than needed but never wider than its box.
     */
    private function width(string $text): float
    {
        return (float) $this->mpdf->GetStringWidth($text);
    }

    private function minimum(float $sizePt): float
    {
        return min($sizePt, (float) config('vouchers.min_font_pt'));
    }
}
