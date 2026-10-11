<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadsData;
use App\Enums\KnowledgeAudience;
use App\Models\Hotel;
use App\Support\Knowledge\KnowledgeSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Knowledge search for the insights agent, with citations it can be held to
 * (SPEC-090).
 *
 * The model never sees a record id. Each distinct source it is shown gets a
 * run-local reference — K1, K2, … — and the agent cites those. The job then
 * turns references back into sources through citation(), so an insight can
 * only cite something this tool actually returned during the run: a made-up
 * reference, or another hotel's source, has nothing to map to.
 *
 * Search runs with the staff audience: insights are read by staff, so staff
 * knowledge may inform them. Knowledge is a help, not a dependency — when
 * search fails, the agent is told to carry on from the operational data.
 */
class InsightKnowledgeSearchTool implements ReadsData, Tool
{
    private readonly KnowledgeSearchTool $search;

    /**
     * Reference => the source as it was shown, keyed in the order seen.
     *
     * @var array<string, array{source_type: string, source_id: string, title: ?string, location: ?string, scope: string, last_updated: ?string}>
     */
    private array $citations = [];

    /** @var array<string, string> source class|id => reference */
    private array $references = [];

    /**
     * Which shown sources still exist, checked once for the run when the
     * first citation is read, and again only if a later search shows more.
     *
     * @var array<string, true>|null
     */
    private ?array $existing = null;

    public function __construct(private readonly Hotel $hotel)
    {
        $this->search = new KnowledgeSearchTool($hotel, KnowledgeAudience::STAFF);
    }

    public function description(): Stringable|string
    {
        return 'Search this hotel\'s knowledge base (its documents, articles and policies, plus shared general guidance) '
            .'for how the hotel says things work. Each result has a "ref" such as "K1": to base an insight on a result, '
            .'list its ref in the insight\'s citations. "scope" is "hotel" (this hotel\'s own source) or "general"; '
            .'follow the hotel source when they disagree. Never use it for live data such as bookings or statuses.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $results = $this->search->searchWithSources($request->string('query')->toString());
        } catch (Throwable $e) {
            report($e);

            return 'Knowledge search is unavailable right now; continue from the operational data.';
        }

        if ($results === []) {
            return 'No relevant results found.';
        }

        return json_encode(array_map(fn (array $result) => [
            'ref' => $this->reference($result),
            'scope' => $result['scope'],
            'source_type' => $result['source_type'],
            'title' => $result['title'],
            'location' => $result['location'],
            'last_updated' => $result['last_updated'],
            'content' => $result['content'],
        ], $results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The source a reference stands for, as it was shown — or null when this
     * tool never returned it, or the source has been deleted since.
     *
     * @return array{source_type: string, source_id: string, title: ?string, location: ?string, scope: string, last_updated: ?string}|null
     */
    public function citation(string $reference): ?array
    {
        $citation = $this->citations[strtoupper(trim($reference))] ?? null;

        if ($citation === null) {
            return null;
        }

        $this->existing ??= KnowledgeSources::existing($this->citations, $this->hotel->id);

        return isset($this->existing[KnowledgeSources::key($citation['source_type'], $citation['source_id'])]) ? $citation : null;
    }

    /**
     * One reference per source, stable for the run: the same document found
     * by two searches is K1 both times.
     *
     * @param  array<string, mixed>  $result
     */
    private function reference(array $result): string
    {
        $key = $result['source_class'].'|'.$result['source_id'];

        if (! isset($this->references[$key])) {
            $reference = 'K'.(count($this->references) + 1);

            $this->references[$key] = $reference;
            $this->existing = null;
            $this->citations[$reference] = [
                'source_type' => $result['source_type'],
                'source_id' => $result['source_id'],
                'title' => $result['title'],
                'location' => $result['location'],
                'scope' => $result['scope'],
                'last_updated' => $result['last_updated'],
            ];
        }

        return $this->references[$key];
    }
}
