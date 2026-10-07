<?php

namespace App\Support\Knowledge\Extraction;

use App\Enums\KnowledgeDocumentFailure;
use RuntimeException;
use Throwable;

/**
 * A document could not be turned into text. Carries the code staff see; the
 * message is the internal detail kept for support, never shown to guests.
 */
class ExtractionFailed extends RuntimeException
{
    public function __construct(
        public readonly KnowledgeDocumentFailure $failure,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail !== '' ? $detail : $failure->value, 0, $previous);
    }
}
