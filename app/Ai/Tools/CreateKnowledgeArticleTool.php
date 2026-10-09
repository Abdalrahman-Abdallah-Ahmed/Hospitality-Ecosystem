<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\KnowledgeBaseCategory;
use App\Models\Hotel;
use App\Services\Knowledge\ArticleCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Records knowledge the admin dictates as one of this hotel's knowledge base
 * articles (FR-022), through ArticleCommands like the article screen. The
 * text is the admin's own: the tool is not a way for the AI to write hotel
 * knowledge. It cannot write hotel policies, documents or the shared global
 * knowledge. Published articles are indexed for search by the usual sync.
 */
class CreateKnowledgeArticleTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Save information the admin dictates as a knowledge base article for this hotel, so staff and guests can be '
            .'answered from it. Use the admin\'s own wording for the content; never add facts, prices or times they did not '
            .'give. Published (searchable) unless the admin asks for a draft. This is not for hotel policies, which the '
            .'admin writes on the policy screen.';
    }

    public function handle(Request $request): Stringable|string
    {
        $data = [
            'title' => trim($request->string('title')->toString()),
            'content' => trim($request->string('content')->toString()),
            'category' => $request->string('category')->toString() ?: KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value,
            'status' => $request->boolean('draft') ? 'draft' : 'published',
        ];

        $article = $this->attempt(function () use ($data) {
            Validator::make($data, [
                'title' => ['required', 'string', 'max:255'],
                'content' => ['required', 'string'],
                'category' => ['required', Rule::enum(KnowledgeBaseCategory::class)],
            ])->validate();

            return app(ArticleCommands::class)->create($this->hotel, $data);
        });

        if (is_string($article)) {
            return $article;
        }

        return $this->done(
            ['article_id' => $article->id],
            ['title' => $article->title, 'status' => $article->status],
            $article->status === 'published' ? 'It will be searchable once indexed, usually within a minute.' : null,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short title.')->required(),
            'content' => $schema->string()->description("The information, in the admin's own words.")->required(),
            'category' => $schema->string()->enum(KnowledgeBaseCategory::class),
            'draft' => $schema->boolean()->description('True to save it as a draft, not yet searchable.'),
        ];
    }
}
