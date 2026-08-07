<?php

namespace App\Ai\Agents;

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
use App\Ai\Tools\GetGuestMessagesTool;
use App\Ai\Tools\GetReservationsTool;
use App\Ai\Tools\GetTasksTool;
use Stringable;

class InsightsAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;


    public function __construct(
        public User $user,
    ) {}
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
            ("reservation", "task", or "guest_message"), or "general" if it does not come from a specific tool.

            Every insight must also include a source_id: the "id" field of the specific record (reservation, task, or
            message) returned by the tool you used to derive that insight. If an insight is category "general" and is
            not about one specific record, use {$this->user->hotel->id} (this hotel's own id) as the source_id instead.
            Never invent a source_id — only use ids you actually saw in a tool result.
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
                        ->description('The id of the specific record this insight is about (from a tool result), or the hotel id for a general insight.')
                        ->required(),
                ]))
                ->max(5)
                ->required(),
        ];
    }
}
