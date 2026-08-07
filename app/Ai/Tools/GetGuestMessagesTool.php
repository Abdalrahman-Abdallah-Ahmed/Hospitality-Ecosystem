<?php

namespace App\Ai\Tools;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
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
        return "Retrieve guest-sent messages from the last 48 hours across all conversations for the current hotel, including message content, sender name, and the related reservation (if any). Use this to spot recurring complaints, unanswered questions, or sentiment trends.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $messages = Message::with(['sender', 'conversation.reservation'])
            ->where('sender_type', Guest::class)
            ->whereHas('conversation', fn ($query) => $query->where('hotel_id', $this->hotel->id))
            ->where('sent_at', '>=', now()->subDays(2))
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'guest_name' => $message->sender
                    ? trim($message->sender->first_name.' '.$message->sender->last_name)
                    : null,
                'content' => $message->content,
                'message_type' => $message->message_type->value,
                'sent_at' => $message->sent_at?->toDateTimeString(),
                'reservation_id' => $message->conversation?->reservation?->reservation_id,
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
