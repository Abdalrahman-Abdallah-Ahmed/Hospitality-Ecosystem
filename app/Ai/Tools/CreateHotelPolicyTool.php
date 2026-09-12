<?php

namespace App\Ai\Tools;

use App\Enums\KnowledgeBaseCategory;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Writes a hotel policy from the conversation.
 *
 * This tool has consequences the others do not, and the description says so
 * to the model as well as to whoever reads this file:
 *
 *  1. Saving an active policy dispatches SyncKnowledgeChunksJob, which embeds
 *     the content. That is a real provider charge, metered as
 *     `embeddings_generated` and costed against this account.
 *  2. The result becomes grounding data. The concierge searches policies when
 *     answering guests and is instructed to let what it finds override its own
 *     judgment — so a policy invented here is a policy quoted to guests as
 *     the hotel's own.
 *
 * Hence the instruction to write only what the admin actually stated. This is
 * the one create tool where a plausible-sounding guess does lasting damage.
 */
class CreateHotelPolicyTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a policy for this hotel (check-in times, cancellation terms, pet rules, and so on). Write only what the admin actually told you — the concierge quotes these policies to guests as the hotel\'s own word, so never fill gaps with a plausible-sounding default. If a detail is missing, ask for it instead of inventing it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $title = $request->string('title')->trim()->toString();
        $content = $request->string('content')->trim()->toString();

        if ($title === '' || $content === '') {
            return 'Both a title and the policy content are required.';
        }

        $policy = HotelPolicy::create([
            'hotel_id' => $this->hotel->id,
            'title' => $title,
            'category' => $request->enum('category', KnowledgeBaseCategory::class, KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES),
            'content' => $content,
            'keywords' => $this->keywords($request),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return "Policy \"{$policy->title}\" recorded (policy id: {$policy->id}). It is now searchable by the guest concierge and may be quoted to guests, so tell the admin what was saved and ask them to confirm the wording.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short title, e.g. "Cancellation policy".')->required(),
            'content' => $schema->string()
                ->description('The policy itself, in the admin\'s own terms. Do not add conditions, times, or amounts they did not state.')
                ->required(),
            'category' => $schema->string()
                ->enum(KnowledgeBaseCategory::class)
                ->description('Which category the policy belongs to.')
                ->default(KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value),
            'keywords' => $schema->array()
                ->items($schema->string())
                ->description('A few words guests might use when asking about this, to help it be found.'),
            'is_active' => $schema->boolean()
                ->description('Whether the policy is in force. An inactive policy is not searchable. Defaults to true.')
                ->default(true),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function keywords(Request $request): ?array
    {
        $keywords = array_values(array_filter(
            array_map('trim', $request->array('keywords')),
            fn ($keyword) => is_string($keyword) && $keyword !== '',
        ));

        return $keywords ?: null;
    }
}
