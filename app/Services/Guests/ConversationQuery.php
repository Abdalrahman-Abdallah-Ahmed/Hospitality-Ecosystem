<?php

namespace App\Services\Guests;

use App\Models\Guest;
use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The hotel's guest conversations, read-only, straight from the laravel/ai
 * tables (D15, SPEC-082).
 *
 * A conversation belongs to a hotel through its guest: the participant must
 * be a Guest of that hotel. The Guest subquery runs through the tenant scope
 * and excludes soft-deleted guests, so a deleted guest's conversations, and
 * every staff (advisor) conversation, are never reached.
 */
class ConversationQuery
{
    /**
     * @return Builder<Conversation>
     */
    public function forHotel(Hotel $hotel): Builder
    {
        $guestMorph = (new Guest)->getMorphClass();

        return Conversation::query()
            ->where('participant_type', $guestMorph)
            ->whereIn('participant_id', Guest::query()->where('hotel_id', $hotel->id)->select('id'));
    }

    /**
     * @param  array{guest_id?: ?string, last_active_from?: ?string, last_active_to?: ?string}  $filters
     */
    public function list(Hotel $hotel, array $filters, int $perPage): LengthAwarePaginator
    {
        $messages = (new ConversationMessage)->getTable();
        $timezone = $hotel->timezone ?: 'UTC';

        $stats = DB::table($messages)
            ->select('conversation_id')
            ->selectRaw('COUNT(*) AS message_count, MIN(created_at) AS started_at, MAX(created_at) AS last_message_at')
            ->tap($this->readable(...))
            ->groupBy('conversation_id');

        return $this->forHotel($hotel)
            ->joinSub($stats, 'stats', 'stats.conversation_id', '=', (new Conversation)->getTable().'.id')
            ->select((new Conversation)->getTable().'.*', 'stats.message_count', 'stats.started_at', 'stats.last_message_at')
            ->when($filters['guest_id'] ?? null, fn (Builder $query, string $guestId) => $query->where('participant_id', $guestId))
            ->when($filters['last_active_from'] ?? null, fn (Builder $query, string $date) => $query->where('stats.last_message_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($filters['last_active_to'] ?? null, fn (Builder $query, string $date) => $query->where('stats.last_message_at', '<=', CarbonImmutable::parse($date, $timezone)->endOfDay()->utc()))
            ->with('participant')
            ->orderByDesc('stats.last_message_at')
            ->paginate($perPage);
    }

    /**
     * One conversation of this hotel, with the same counts as the list, or
     * null when it is not one of the hotel's guest conversations.
     */
    public function find(Hotel $hotel, string $conversationId): ?Conversation
    {
        $conversation = $this->forHotel($hotel)->with('participant')->find($conversationId);

        if ($conversation === null) {
            return null;
        }

        $stats = $conversation->messages()
            ->tap($this->readable(...))
            ->selectRaw('COUNT(*) AS message_count, MIN(created_at) AS started_at, MAX(created_at) AS last_message_at')
            ->toBase()
            ->first();

        return $conversation->forceFill((array) $stats);
    }

    /**
     * What the guest and the Concierge said, oldest first.
     */
    public function messages(Conversation $conversation, int $perPage): LengthAwarePaginator
    {
        return $conversation->messages()
            ->tap($this->readable(...))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Only what a person said or was told. Tool rows, and assistant rows that
     * only called tools, carry no text for anyone to read; they are neither
     * shown nor counted.
     */
    private function readable(Builder|HasMany|QueryBuilder $query): void
    {
        $query->whereIn('role', ['user', 'assistant'])->whereRaw("TRIM(content) <> ''");
    }
}
