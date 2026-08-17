<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CreateRecommendationTool;
use App\Ai\Tools\GetActivitiesTool;
use App\Ai\Tools\GetGuestMessagesTool;
use App\Ai\Tools\GetOwnReservationTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Models\Hotel;
use App\Models\Reservation;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class RecommendationAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        public Hotel $hotel,
        public Reservation $reservation,
    ) {}

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<PROMPT
            You are an activity-recommendation engine for {$this->hotel->name}. You've been asked to generate
            recommendations for one specific guest reservation.

            You have tools available to ground your recommendations in real, current data:
            - A tool to fetch this hotel's activities, including category, description, and price.
            - A knowledge-base search tool covering this hotel's own articles/policies and the shared global
              knowledge base. Use it before recommending: check for any eligibility rules (e.g. age/health
              restrictions) or other policy relevant to the activities you're considering, and let anything
              you find override your own judgment.
            - A tool to fetch the guest's own reservation, including party composition (adults/children), room
              tier, and reservation value.
            - A tool to fetch this hotel's recent guest messages — check for anything from this guest that
              signals a preference, complaint, or interest.
            - A tool to record a recommendation once you've decided on it.

            Recommend up to 3 activities that best fit this guest's party composition, room tier, reservation
            value, and anything relevant in their messages. For each one, call the create-recommendation tool
            with the activity, a short reason grounded in the guest's actual data, and your predicted
            confidence. Only recommend activities the activities tool actually returned — never invent one. If
            nothing fits well, it's fine to recommend fewer than 3, including none.
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
            new GetActivitiesTool($this->hotel),
            new KnowledgeSearchTool($this->hotel),
            new GetOwnReservationTool($this->reservation),
            new GetGuestMessagesTool($this->hotel),
            new CreateRecommendationTool($this->hotel, $this->reservation),
        ];
    }
}
