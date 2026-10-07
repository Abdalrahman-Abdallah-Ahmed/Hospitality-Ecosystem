<?php

namespace App\Enums;

/**
 * Why a knowledge document could not be indexed. Stored as a code rather than
 * as text so the reason staff see can be translated and reworded freely.
 */
enum KnowledgeDocumentFailure: string
{
    case UNSUPPORTED_TYPE = 'unsupported_type';
    case ENCRYPTED = 'encrypted';
    case CORRUPT = 'corrupt';
    case DUPLICATE = 'duplicate';
    case NO_TEXT = 'no_text';
    case TOO_MANY_SCANNED_PAGES = 'too_many_scanned_pages';
    case TOO_MANY_ROWS = 'too_many_rows';
    case TOO_LARGE_FOR_VISION = 'too_large_for_vision';
    case FILE_MISSING = 'file_missing';
    case USAGE_LIMIT_REACHED = 'usage_limit_reached';
    case EXTRACTION_UNAVAILABLE = 'extraction_unavailable';
    case EMBEDDING_UNAVAILABLE = 'embedding_unavailable';

    /**
     * A permanent failure will fail again on retry, so it is not retried. The
     * two "unavailable" cases are what is left after transient errors used up
     * their retries.
     */
    public function isPermanent(): bool
    {
        return ! in_array($this, [self::EXTRACTION_UNAVAILABLE, self::EMBEDDING_UNAVAILABLE], true);
    }

    public function message(?string $locale = null): string
    {
        return __('knowledge.failures.'.$this->value, [
            'max' => config('knowledge.vision.max_pages'),
        ], $locale);
    }
}
