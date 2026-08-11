<?php

namespace App\Support\Knowledge;

/**
 * Splits raw text into roughly token-sized chunks for embedding, preferring
 * paragraph boundaries, falling back to sentences, then raw characters for
 * a pathological single sentence with no punctuation to break on.
 */
class TextChunker
{
    private const CHARS_PER_TOKEN = 3.5;

    /**
     * @return array<int, string>
     */
    public static function chunk(string $text, int $maxTokens = 800): array
    {
        $maxChars = (int) ($maxTokens * self::CHARS_PER_TOKEN);

        return self::pack(self::split($text, '/\n\s*\n/'), "\n\n", $maxChars);
    }

    /**
     * Rough token estimate for a piece of text, using the same
     * chars-per-token heuristic as chunk() itself.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) round(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Greedily pack units (paragraphs or sentences) into chunks up to
     * $maxChars, joined by $glue. A unit larger than $maxChars on its own
     * is broken down further (paragraph -> sentences -> raw characters)
     * before packing.
     *
     * @param  array<int, string>  $units
     * @return array<int, string>
     */
    private static function pack(array $units, string $glue, int $maxChars): array
    {
        $chunks = [];
        $buffer = '';

        foreach ($units as $unit) {
            if (mb_strlen($unit) > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                array_push($chunks, ...self::breakDown($unit, $glue, $maxChars));

                continue;
            }

            $candidate = $buffer === '' ? $unit : $buffer.$glue.$unit;

            if (mb_strlen($candidate) > $maxChars) {
                $chunks[] = $buffer;
                $buffer = $unit;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private static function breakDown(string $unit, string $glue, int $maxChars): array
    {
        return match ($glue) {
            "\n\n" => self::pack(self::split($unit, '/(?<=[.!?])\s+/'), ' ', $maxChars),
            default => mb_str_split($unit, $maxChars),
        };
    }

    /**
     * @return array<int, string>
     */
    private static function split(string $text, string $pattern): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        $units = preg_split($pattern, trim($normalized)) ?: [$normalized];

        return array_values(array_filter(array_map('trim', $units), fn (string $unit) => $unit !== ''));
    }
}
