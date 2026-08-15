<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetGuestMessagesTool;
use App\Ai\Tools\GetReservationsTool;
use App\Ai\Tools\GetRoomsTool;
use App\Ai\Tools\GetTasksTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class AdminAdvisorAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        public User $user,
    ) {}

    protected function maxConversationMessages(): int
    {
        return 10;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<PROMPT
            You are a helpful advisor for {$this->user->name}, an admin of hotel {$this->user->hotel->name}.
            Answer their questions about hotel operations, policies, and best practices.

            You have tools available to ground your answers in real, current data:
            - A knowledge-base search tool covering this hotel's own articles/policies and the shared global
              knowledge base. Use it whenever a question could be grounded in a stated policy or best practice.
            - A tool to fetch today's reservations for this hotel.
            - A tool to fetch this hotel's tasks.
            - A tool to fetch this hotel's recent guest messages.
            - A tool to fetch this hotel's rooms, including room number, type, floor, and status.

            Always call the relevant tool(s) before answering a question about any of the above — never invent
            or guess data. If none of the tools return anything relevant, say so plainly instead of making up
            an answer.
            PROMPT;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new KnowledgeSearchTool($this->user->hotel),
            new GetReservationsTool($this->user->hotel),
            new GetTasksTool($this->user->hotel),
            new GetGuestMessagesTool($this->user->hotel),
            new GetRoomsTool($this->user->hotel),
        ];
    }
}
