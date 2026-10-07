<?php

namespace App\Support\Knowledge;

use App\Support\Knowledge\Extraction\Segment;
use Normalizer;

/**
 * Cheap, deterministic clean-up of extracted text. Deterministic matters: the
 * same file must always produce the same passages, or a rebuild would change
 * what search returns.
 *
 * Tatweel and diacritics are kept on purpose; they can change meaning in
 * names.
 */
class TextNormalizer
{
    private const EDGE_MIN_SEGMENTS = 3;

    private const EDGE_MIN_SHARE = 0.6;

    public static function normalize(string $text): string
    {
        $text = self::toUtf8($text);

        // NFKC folds Arabic presentation forms (what many PDFs store) back
        // into ordinary letters, so they match what a guest types.
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Drop running headers and footers: a first or last non-empty line that
     * repeats on most pages of a document of three or more pages.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    public static function stripRepeatedEdges(array $segments): array
    {
        if (count($segments) < self::EDGE_MIN_SEGMENTS) {
            return $segments;
        }

        $threshold = (int) ceil(count($segments) * self::EDGE_MIN_SHARE);
        $first = [];
        $last = [];

        foreach ($segments as $segment) {
            $lines = self::lines($segment->text);

            if ($lines === []) {
                continue;
            }

            $first[$lines[0]] = ($first[$lines[0]] ?? 0) + 1;
            $last[end($lines)] = ($last[end($lines)] ?? 0) + 1;
        }

        $headers = array_keys(array_filter($first, fn (int $count) => $count >= $threshold));
        $footers = array_keys(array_filter($last, fn (int $count) => $count >= $threshold));

        if ($headers === [] && $footers === []) {
            return $segments;
        }

        return array_map(function (Segment $segment) use ($headers, $footers) {
            $lines = self::lines($segment->text);

            if ($lines !== [] && in_array($lines[0], $headers, true)) {
                array_shift($lines);
            }

            if ($lines !== [] && in_array(end($lines), $footers, true)) {
                array_pop($lines);
            }

            return new Segment($segment->location, trim(implode("\n", $lines)));
        }, $segments);
    }

    /**
     * Best-effort conversion of legacy single-byte encodings. Windows-1256 is
     * tried before Latin-1 because Arabic CSV exports from Excel use it.
     */
    public static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            return substr($text, 3);
        }

        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $encoding = str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';

            return mb_convert_encoding(substr($text, 2), 'UTF-8', $encoding);
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $text);

        if ($converted !== false && $converted !== '') {
            return $converted;
        }

        return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn (string $line) => $line !== '',
        ));
    }
}
