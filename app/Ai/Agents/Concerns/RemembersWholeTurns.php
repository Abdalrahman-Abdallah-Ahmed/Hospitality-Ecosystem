<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

/**
 * RemembersConversations, but the remembered history always starts at a
 * message from the person.
 *
 * The history is the last N stored messages, and N can cut into the middle
 * of a turn, so it may start with the assistant's tool call or its result.
 * Gemini rejects a history whose first turn is a tool call ("function call
 * turn comes immediately after a user turn"), failing the whole message. The
 * leading partial turn is dropped instead: the model loses one half-turn of
 * old context, never the conversation.
 */
trait RemembersWholeTurns
{
    use RemembersConversations {
        messages as private latestStoredMessages;
    }

    /**
     * @return array<int, Message>
     */
    public function messages(): iterable
    {
        return collect($this->latestStoredMessages())
            ->skipUntil(fn (Message $message) => $message->role === MessageRole::User)
            ->values()
            ->all();
    }
}
