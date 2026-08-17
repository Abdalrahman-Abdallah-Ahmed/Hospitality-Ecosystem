<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CreateReservationTool;
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
              knowledge base. Use it not only when a question could be grounded in a stated policy or best
              practice, but also before you act: before creating a reservation, check for any relevant booking
              policy or SOP. Let anything you find override your own judgment.
            - A tool to fetch today's reservations for this hotel.
            - A tool to fetch this hotel's tasks.
            - A tool to fetch this hotel's recent guest messages.
            - A tool to fetch this hotel's rooms, including room number, type, floor, and status.
            - A tool to create a reservation for this hotel, matching the guest by phone number.

            Always call the relevant tool(s) before answering a question about any of the above — never invent
            or guess data. If none of the tools return anything relevant, say so plainly instead of making up
            an answer.

            You may be sent a photo or screenshot of reservation details (e.g. from a booking platform, ID, or
            handwritten note). Read every visible detail from it and use the create-reservation tool to create
            the reservation. The guest's phone number, arrival date, and departure date are required — if any
            of those is missing or illegible in the image, ask the admin to confirm or provide it rather than
            guessing. Confirm back to the admin what was created, including anything you couldn't read clearly.
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
            new CreateReservationTool($this->user->hotel),
        ];
    }
}
