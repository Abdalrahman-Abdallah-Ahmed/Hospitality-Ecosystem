<?php

namespace App\Ai\Agents;

use App\Enums\PitchOpening;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads one guest message and says whether it is a complaint and whether it
 * opens the door to a suggestion. Nothing else.
 *
 * Deliberately separate from the concierge: the agent told it may suggest an
 * activity is the wrong judge of whether this guest is too unhappy to be sold
 * to. Its output is a gate, never a ranking.
 */
class TurnSignalAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $categories  activity category id => name
     */
    public function __construct(
        public array $categories,
    ) {}

    public function instructions(): Stringable|string
    {
        $openings = implode(', ', array_map(fn (PitchOpening $opening) => $opening->value, PitchOpening::cases()));
        $categories = $this->categories === []
            ? '(none)'
            : collect($this->categories)->map(fn (string $name, string $id) => "- {$id}: {$name}")->implode("\n");

        return <<<PROMPT
            You classify one WhatsApp message a hotel guest just sent. Guests write in any language, often
            mixed. You are given a few earlier messages for context, but classify only the LAST guest message.

            complaint: true if the guest is unhappy, reports a problem, or asks for something to be fixed —
            even politely, even alongside a question ("the room is fine but the pool was freezing, what else
            is there?" is a complaint). False otherwise. When unsure, answer true.

            opening: the one value that best describes an invitation to suggest an activity, or null.
            Allowed values: {$openings}.
            - asks_what_to_do: the guest asks what they could do, or for a suggestion or recommendation.
            - asks_about_activities: the guest asks about the hotel's activities, tours, classes or treatments.
            - beach_or_pool, evening_plans, boredom, children, weather: the guest mentions this without asking.
            Use null when the message is only a request, a question about the stay, thanks, or small talk.

            interest_category_id: if the guest asks about one specific kind of activity, the id of the matching
            category from this list, otherwise null. Never invent an id.
            {$categories}

            evidence_quote: the guest's own words, copied exactly from the last message, that your
            classification rests on. Empty only when complaint is false and opening is null.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'complaint' => $schema->boolean()->required(),
            // null is listed as an allowed value as well as a type, or a
            // strict provider rejects "no opening", the most common answer.
            'opening' => $schema->string()
                ->enum([...array_map(fn (PitchOpening $opening) => $opening->value, PitchOpening::cases()), null])
                ->nullable()
                ->required(),
            'interest_category_id' => $schema->string()->nullable()->required(),
            'evidence_quote' => $schema->string()->required(),
        ];
    }
}
