<?php

namespace App\Support\Audit;

use App\Models\Activity;
use App\Models\AiInsights;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Models\KnowledgeBaseArticle;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\Task;
use App\Models\Transaction;

/**
 * The record types whose history can be read at /api/history/{type}/{id},
 * keyed by URL slug. Shared by HistoryController and the AI activity list,
 * which links each entry's target to its history (SPEC-083 FR-034).
 */
class HistorySubjects
{
    /**
     * Readable at /api/history/{slug}/{id}.
     *
     * @var array<string, class-string>
     */
    public const READABLE = [
        'reservation' => Reservation::class,
        'transaction' => Transaction::class,
        'stay' => Stay::class,
        'guest' => Guest::class,
        'recommendation' => Recommendation::class,
        'task' => Task::class,
        'ai-insights' => AiInsights::class,
        'activity' => Activity::class,
        'knowledge-base-article' => KnowledgeBaseArticle::class,
        'booking' => Booking::class,
        'room' => Room::class,
        'room-type' => RoomType::class,
        'guest-memory' => GuestMemory::class,
    ];

    /**
     * Named in the AI activity list, but with no history of their own.
     *
     * @var array<string, class-string>
     */
    private const NAMED_ONLY = [
        'proactive-message' => ProactiveMessage::class,
    ];

    /**
     * @return class-string|null
     */
    public static function classFor(string $slug): ?string
    {
        return self::READABLE[$slug] ?? self::NAMED_ONLY[$slug] ?? null;
    }

    /**
     * The slug for a stored morph type (the class name), or null.
     */
    public static function slugFor(?string $morphType): ?string
    {
        if ($morphType === null) {
            return null;
        }

        $slug = array_search($morphType, [...self::READABLE, ...self::NAMED_ONLY], true);

        return $slug === false ? null : $slug;
    }

    /**
     * Every slug an AI activity entry can name as its target.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys([...self::READABLE, ...self::NAMED_ONLY]);
    }

    public static function hasHistory(string $slug): bool
    {
        return isset(self::READABLE[$slug]);
    }
}
