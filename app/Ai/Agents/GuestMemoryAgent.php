<?php

namespace App\Ai\Agents;

use App\Enums\GuestMemoryCategory;
use App\Enums\GuestMemoryConfidence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads what a guest and the Concierge said during one stay and proposes
 * what is worth remembering about the guest (SPEC-084). It only proposes:
 * GuestMemoryService decides what is kept, and enforces every rule below in
 * code as well.
 */
class GuestMemoryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You keep short notes about a hotel guest, so the hotel's assistant can welcome them back. You are
            given what the guest and the assistant said during one stay, and the notes already kept about the
            guest. Propose changes to the notes.

            Keep only durable facts about the guest: preferences (room, food, amenities), how they like to be
            contacted (language, channel, times), recurring interests (activities, experiences), and lasting needs
            the guest stated (for example a wheelchair user in the party).

            Never keep:
            - temporary requests ("towels to room 204") or anything about this stay only;
            - booking facts: dates, room numbers, prices, booking or reservation statuses;
            - payment details, card numbers, identity or passport numbers, emails or phone numbers;
            - health details beyond an accessibility need the guest stated.

            Write each note as one short sentence, in the language the guest used. Use confidence "high" when the
            guest said it plainly, "medium" when it is clearly implied, "low" otherwise.

            Operations:
            - create: a new note.
            - refresh: the guest said again something a note already says (in any language) — give its id.
            - replace: the guest said something that changes or contradicts a note — give its id and the new note.
            Prefer refresh or replace over create when a note already covers the fact. Propose nothing when there
            is nothing durable. Never invent a fact the guest did not express.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operations' => $schema->array()
                ->items($schema->object([
                    'op' => $schema->string()->enum(['create', 'refresh', 'replace'])->required(),
                    'id' => $schema->string()->nullable()->description('The note to refresh or replace; null for create.')->required(),
                    'category' => $schema->string()->enum([...array_map(fn (GuestMemoryCategory $c) => $c->value, GuestMemoryCategory::cases()), null])->nullable()->required(),
                    'content' => $schema->string()->nullable()->required(),
                    'language' => $schema->string()->enum(['en', 'ar', null])->nullable()->required(),
                    'confidence' => $schema->string()->enum(array_map(fn (GuestMemoryConfidence $c) => $c->value, GuestMemoryConfidence::cases()))->required(),
                ]))
                ->required(),
        ];
    }
}
