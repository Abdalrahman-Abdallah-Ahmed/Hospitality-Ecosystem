<?php

namespace App\Support\Knowledge\Extraction;

/**
 * One located piece of a source's text: a PDF page, a spreadsheet row range,
 * a document section. Every passage is cut from exactly one segment, which is
 * how a citation can say where in the source an answer came from.
 */
final readonly class Segment
{
    public function __construct(
        public ?string $location,
        public string $text,
    ) {}

    /**
     * @return array{location: ?string, text: string}
     */
    public function toArray(): array
    {
        return ['location' => $this->location, 'text' => $this->text];
    }

    /**
     * @param  array{location?: ?string, text?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['location'] ?? null, (string) ($data['text'] ?? ''));
    }
}
