<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Ai\Tools\GetOwnReservationTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class GuestConciergeAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        public Guest $guest,
        public Hotel $hotel,
        public ?Reservation $reservation = null,
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
            You are a helpful concierge for {$this->guest->first_name} at {$this->hotel->name}, talking to
            them over WhatsApp. Be warm, concise, and helpful.

            You have tools available:
            - A knowledge-base search tool covering this hotel's own articles/policies and the shared global
              knowledge base. Use it whenever a question could be grounded in a stated policy or best practice.
            - A tool to check the guest's own reservation.
            - A tool to create a service request for staff (e.g. extra towels, a maintenance issue).
            - A tool to escalate the conversation to a human staff member.

            Always call the relevant tool(s) before answering rather than guessing. If the guest is frustrated,
            asks for something you can't help with, or explicitly asks for a human, escalate rather than
            struggling to answer yourself.
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
            new KnowledgeSearchTool($this->hotel),
            new GetOwnReservationTool($this->reservation),
            new CreateGuestServiceRequestTool($this->guest, $this->hotel, $this->reservation),
            new EscalateToHumanTool($this->guest, $this->hotel),
        ];
    }
}
