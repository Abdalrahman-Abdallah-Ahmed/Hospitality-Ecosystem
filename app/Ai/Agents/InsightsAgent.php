<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetGuestMessagesTool;
use App\Ai\Tools\GetReservationsTool;
use App\Ai\Tools\GetTasksTool;
use App\Ai\Tools\InsightKnowledgeSearchTool;
use App\Enums\AiInsightCategories;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class InsightsAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Held on the agent so the job can map the model's citation references
     * back to the sources this run was actually shown (SPEC-090).
     */
    public InsightKnowledgeSearchTool $knowledgeTool;

    public function __construct(
        public User $user,
    ) {
        $this->knowledgeTool = new InsightKnowledgeSearchTool($user->hotel);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<PROMPT
            You are a helpful insights agent for {$this->user->name} that owns hotel {$this->user->hotel->name} that provides insights about various resources.
            The resource will be provided in the prompt. You should provide insights about the resource and provide actionable insights.

            You have tools available to fetch real, current data about the hotel's reservations, tasks, and guest messages.
            Always call the relevant tool(s) to ground your insights in real data before responding — never invent or guess data.
            Respond with at most 5 insights. Each insight must be tagged with the category of the tool it came from
            ("reservation", "task", or "guest_message"), "knowledge" if it rests only on the knowledge base, or "general"
            if it does not come from a specific tool.

            Every insight must also include a source_id: the "id" field of the specific record (reservation, task, or
            message) returned by the tool you used to derive that insight. If an insight is category "general" and is
            not about one specific record, use {$this->user->hotel->id} (this hotel's own id) as the source_id instead.
            Never invent a source_id — only use ids you actually saw in a tool result.

            Guest messages are for finding themes. Summarize themes; never quote a guest's message word for word.

            You can also search the hotel's knowledge base (its policies, articles and documents) to check what the
            hotel says against what is happening. Knowledge describes how the hotel says things work, not what
            happened: it never proves an insight on its own. When an insight relies on a knowledge result, list that
            result's "ref" (such as "K1") in the insight's citations. Cite only refs the knowledge tool returned in this
            conversation, never invent one, and leave citations empty when no knowledge was used. When a hotel source
            and a general source disagree, follow the hotel source. Use the category "knowledge" only when an insight
            rests on knowledge alone and on no specific record, with this hotel's id as the source_id.
            PROMPT;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new GetReservationsTool($this->user->hotel),
            new GetTasksTool($this->user->hotel),
            new GetGuestMessagesTool($this->user->hotel),
            $this->knowledgeTool,
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'insights' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'category' => $schema->string()
                        ->enum(AiInsightCategories::class)
                        ->required(),
                    'source_id' => $schema->string()
                        ->description('The id of the specific record this insight is about (from a tool result), or the hotel id for a general or knowledge insight.')
                        ->required(),
                    'citations' => $schema->array()
                        ->items($schema->string())
                        ->description('The refs (such as "K1") of the knowledge results this insight relies on, as returned by the knowledge tool. Empty when no knowledge was used.')
                        ->required(),
                ]))
                ->max(5)
                ->required(),
        ];
    }
}
