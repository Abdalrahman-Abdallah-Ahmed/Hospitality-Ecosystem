<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guest conversation in the viewer (SPEC-082). The counts and times come
 * from ConversationQuery and cover only readable messages.
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $guest = $this->participant;

        return [
            'id' => $this->id,
            'guest' => $guest ? [
                'id' => $guest->id,
                'name' => trim($guest->first_name.' '.$guest->last_name),
                'phone' => $guest->phone_number,
            ] : null,
            'message_count' => (int) $this->message_count,
            'started_at' => $this->timestamp($this->started_at),
            'last_message_at' => $this->timestamp($this->last_message_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
