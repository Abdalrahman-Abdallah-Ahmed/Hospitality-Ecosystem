<?php

namespace App\Support\Ai;

use App\Ai\Agents\GuestConciergeAgent;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Writes a message the Concierge sent without a model call — a proactive
 * message (SPEC-073) — into the guest's conversation, so staff see it in the
 * conversation and the guest's reply continues it.
 *
 * Uses the conversation the Concierge itself would continue, or starts one.
 * The row has the shape the laravel/ai store writes for an assistant turn.
 */
class ConversationAppender
{
    public function __construct(
        private readonly ConversationStore $store,
    ) {}

    /**
     * @return string the conversation id
     */
    public function appendAssistantMessage(Guest $guest, string $text): string
    {
        $type = Conversation::participantType($guest);
        $key = Conversation::participantKey($guest);

        $conversationId = $this->store->latestConversationId($type, $key)
            ?? $this->store->storeConversation($type, $key, Str::limit($text, 100, preserveWords: true));

        $now = now();

        DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => $type,
            'participant_id' => $key,
            'agent' => GuestConciergeAgent::class,
            'role' => 'assistant',
            'content' => $text,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['proactive' => true]),
            'approval_state' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))
            ->where('id', $conversationId)
            ->update(['updated_at' => $now]);

        return $conversationId;
    }
}
