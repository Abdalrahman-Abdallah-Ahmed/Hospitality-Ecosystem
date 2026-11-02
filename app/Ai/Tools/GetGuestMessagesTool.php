<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadsData;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetGuestMessagesTool implements ReadsData, Tool
{
    /**
     * With a guest, only that guest's own messages: an agent working for one
     * guest (recommendations) must never see what other guests wrote, or a
     * reason the guest later hears could rest on someone else's words.
     * Without one, the whole hotel's, for finding themes (insights).
     */
    public function __construct(
        private readonly Hotel $hotel,
        private readonly ?Guest $guest = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        if ($this->guest) {
            return 'Retrieve this guest\'s own messages from the last 48 hours, including message content and the related agent conversation id. Use this to spot the guest\'s preferences, complaints, or interests. Never returns another guest\'s messages.';
        }

        return 'Retrieve guest-authored messages from the last 48 hours in remembered agent conversations for the current hotel, including message content, guest name, and the related agent conversation id. Use this to spot recurring complaints, unanswered questions, or sentiment trends.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $messages = Guest::hotelConversationMessagesQuery($this->hotel)
            ->when($this->guest, fn ($query) => $query
                ->whereHas('conversation', fn ($conversation) => $conversation->where('participant_id', $this->guest->id)))
            ->with('conversation.participant')
            ->where('created_at', '>=', now()->subDays(2))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ConversationMessage $message) => [
                'id' => $message->id,
                // The guest's own name adds nothing for a one-guest agent.
                ...($this->guest ? [] : ['guest_name' => $this->guestName($message)]),
                'content' => $message->content,
                'sent_at' => $message->created_at?->toDateTimeString(),
                'conversation_id' => $message->conversation_id,
            ])
            ->values();

        return json_encode($messages);
    }

    private function guestName(ConversationMessage $message): ?string
    {
        $participant = $message->conversation?->participant;

        return $participant ? trim($participant->first_name.' '.$participant->last_name) : null;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
