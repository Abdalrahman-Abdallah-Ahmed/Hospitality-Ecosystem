<?php

namespace App\Support\Knowledge\Extraction;

final readonly class ExtractionResult
{
    /**
     * @param  list<Segment>  $segments
     * @param  list<int>  $scannedPageNumbers  1-based pages with no text layer, for AI vision
     */
    public function __construct(
        public array $segments,
        public ?int $pageCount = null,
        public array $scannedPageNumbers = [],
    ) {}

    public function scannedPageCount(): int
    {
        return count($this->scannedPageNumbers);
    }
}
