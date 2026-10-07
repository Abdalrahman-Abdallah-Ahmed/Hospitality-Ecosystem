<?php

namespace App\Support\Knowledge\Extraction;

/**
 * Turns one stored file into located segments. Implementations only ever
 * read the file; a failure throws ExtractionFailed and leaves it untouched.
 */
interface Extractor
{
    public function supports(string $mimeType): bool;

    /**
     * @throws ExtractionFailed
     */
    public function extract(string $absolutePath, string $mimeType): ExtractionResult;
}
