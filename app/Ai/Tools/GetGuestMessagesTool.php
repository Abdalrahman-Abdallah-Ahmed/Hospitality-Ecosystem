<?php

namespace App\Ai\Tools;

use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetGuestMessagesTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieve guest-authored messages from the last 48 hours in remembered agent conversations for the current hotel, including message content, guest name, and the related agent conversation id. Use this to spot recurring complaints, unanswered questions, or sentiment trends.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $messages = Guest::hotelConversationMessagesQuery($this->hotel)
            ->with('conversation.participant')
            ->where('created_at', '>=', now()->subDays(2))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ConversationMessage $message) => [
                'id' => $message->id,
                'guest_name' => $message->conversation?->participant
                    ? trim($message->conversation->participant->first_name.' '.$message->conversation->participant->last_name)
                    : null,
                'content' => $message->content,
                'sent_at' => $message->created_at?->toDateTimeString(),
                'conversation_id' => $message->conversation_id,
            ])
            ->values();

        return json_encode($messages);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
