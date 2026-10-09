<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Services\Knowledge\ArticleCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Corrects one of this hotel's knowledge base articles with text the admin
 * gives (FR-022). Global articles are not this hotel's to change, so they are
 * "not found" here; policies and documents have no AI write path at all.
 */
class UpdateKnowledgeArticleTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Correct the title or content of one of this hotel\'s knowledge base articles, by article id, using the '
            .'admin\'s own wording. Send only what changes. Shared (global) knowledge and hotel policies cannot be changed here.';
    }

    public function handle(Request $request): Stringable|string
    {
        $article = $this->findOwn(KnowledgeBaseArticle::class, $request->string('article_id')->toString());

        if (! $article) {
            return 'This hotel has no knowledge base article with that id.';
        }

        $changes = array_filter([
            'title' => trim($request->string('title')->toString()) ?: null,
            'content' => trim($request->string('content')->toString()) ?: null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            return 'Nothing to change: give the new title or content.';
        }

        $result = $this->attempt(function () use ($article, $changes) {
            Validator::make($changes, [
                'title' => ['sometimes', 'string', 'max:255'],
                'content' => ['sometimes', 'string'],
            ])->validate();

            return app(ArticleCommands::class)->update($article, $changes, $this->hotel);
        });

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['article_id' => $article->id], array_map(fn ($value) => mb_strimwidth($value, 0, 80, '…'), $changes));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'article_id' => $schema->string()->required(),
            'title' => $schema->string(),
            'content' => $schema->string()->description("The corrected text, in the admin's own words."),
        ];
    }
}
