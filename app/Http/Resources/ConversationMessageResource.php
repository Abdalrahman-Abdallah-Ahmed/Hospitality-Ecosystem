<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message as a person reads it. Tool calls, tool results, model usage
 * and the raw meta are never returned, and attachments carry their type and
 * name only, never their content (SPEC-082 FR-006).
 */
class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->role === 'user' ? 'guest' : 'concierge',
            'text' => $this->content,
            'sent_at' => $this->created_at?->toIso8601String(),
            'proactive' => (bool) (($this->meta ?? [])['proactive'] ?? false),
            'attachments' => collect($this->attachments ?? [])
                ->filter(fn ($attachment) => is_array($attachment))
                ->map(fn (array $attachment) => [
                    'type' => $attachment['type'] ?? null,
                    'name' => $attachment['name'] ?? $attachment['filename'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }
}
