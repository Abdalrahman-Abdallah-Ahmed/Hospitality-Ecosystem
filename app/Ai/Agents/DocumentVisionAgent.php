<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the text in an image, or in the image-only pages of a PDF, so it can
 * be indexed like any other document. It transcribes and briefly describes;
 * it never adds, infers or translates, because whatever it returns is what
 * the Concierge will later tell guests.
 *
 * No tools and no conversation: one attachment in, one structured
 * transcription out. The indexing job does all the writing.
 */
class DocumentVisionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<int>  $pages  1-based page numbers to transcribe; [1] for an image
     */
    public function __construct(
        public array $pages,
    ) {}

    public function instructions(): Stringable|string
    {
        $pages = implode(', ', $this->pages);

        return <<<PROMPT
            You transcribe hotel documents so they can be searched. You are given one file: an image, or a PDF.
            Transcribe ONLY these page numbers: {$pages}. For an image, use page 1.

            For each page:
            - text: every piece of visible text, copied verbatim, in its original language and reading order.
              Arabic stays Arabic, English stays English. Keep line breaks between separate lines or table rows,
              and keep table rows together as "column: value" pairs when a table is clearly visible.
            - description: at most two sentences saying what the page or image shows (for example "A printed
              price board for the spa."). Do not repeat the text in it.

            Never infer, guess, translate, correct or add anything that is not visible. If a page has no
            readable text, return an empty text for it. Return exactly one entry per listed page.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pages' => $schema->array()->items($schema->object([
                'page' => $schema->integer()->required(),
                'text' => $schema->string()->required(),
                'description' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
