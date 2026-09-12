<?php

namespace App\Enums;

/**
 * Everything the system counts. WP-6's features catalogue is deferred, so
 * this enum is the catalogue for now — a fixed list in code rather than a
 * table, because nothing yet needs to add a feature without a deploy.
 *
 * Three categories, one table. They answer different questions and are easy
 * to confuse:
 *
 *  - Cost drivers   — what we pay for. Summed from events.
 *  - Value signals  — what the customer gets. Summed from events.
 *  - Scale          — what exists right now. Recounted, never summed.
 *
 * Feature codes are permanent. Once a code has been written to meter_events
 * it must never be renamed, or the history stops adding up.
 */
enum MeterFeature: string
{
    // Cost drivers.
    case AI_MESSAGES = 'ai_messages';
    case AI_INSIGHTS_GENERATED = 'ai_insights_generated';
    case RECOMMENDATIONS_GENERATED = 'recommendations_generated';
    case EMBEDDINGS_GENERATED = 'embeddings_generated';

    // Value signals.
    case RECOMMENDATIONS_DELIVERED = 'recommendations_delivered';
    case BOOKINGS_CREATED = 'bookings_created';
    case BOOKINGS_REALISED = 'bookings_realised';
    case CONVERSATIONS_HANDLED = 'conversations_handled';
    case TRANSACTION_ROWS_IMPORTED = 'transaction_rows_imported';

    // Scale.
    case PROPERTIES = 'properties';
    case USERS = 'users';
    case GUESTS = 'guests';
    case STAYS = 'stays';

    public function unit(): string
    {
        return match ($this) {
            self::AI_MESSAGES => 'messages',
            self::AI_INSIGHTS_GENERATED => 'insights',
            self::RECOMMENDATIONS_GENERATED, self::RECOMMENDATIONS_DELIVERED => 'recommendations',
            self::EMBEDDINGS_GENERATED => 'chunks',
            self::BOOKINGS_CREATED, self::BOOKINGS_REALISED => 'bookings',
            self::CONVERSATIONS_HANDLED => 'conversations',
            self::TRANSACTION_ROWS_IMPORTED => 'rows',
            self::PROPERTIES => 'properties',
            self::USERS => 'users',
            self::GUESTS => 'guests',
            self::STAYS => 'stays',
        };
    }

    /**
     * Which of the three kinds of thing this counts. They share one table but
     * answer different questions, and a report that mixes them is describing
     * nothing in particular:
     *
     *  - cost_driver  — what we pay for
     *  - value_signal — what the customer gets
     *  - seat         — what exists right now
     */
    public function category(): string
    {
        return match ($this) {
            self::AI_MESSAGES,
            self::AI_INSIGHTS_GENERATED,
            self::RECOMMENDATIONS_GENERATED,
            self::EMBEDDINGS_GENERATED => 'cost_driver',

            self::RECOMMENDATIONS_DELIVERED,
            self::BOOKINGS_CREATED,
            self::BOOKINGS_REALISED,
            self::CONVERSATIONS_HANDLED,
            self::TRANSACTION_ROWS_IMPORTED => 'value_signal',

            self::PROPERTIES, self::USERS, self::GUESTS, self::STAYS => 'seat',
        };
    }

    /**
     * A short, non-technical name for a customer-facing screen. Feature codes
     * are permanent and therefore ugly; this is the part that may be reworded
     * freely, because nothing is keyed on it.
     */
    public function label(): string
    {
        return match ($this) {
            self::AI_MESSAGES => 'AI messages',
            self::AI_INSIGHTS_GENERATED => 'AI insights',
            self::RECOMMENDATIONS_GENERATED => 'Recommendations generated',
            self::RECOMMENDATIONS_DELIVERED => 'Recommendations delivered',
            self::EMBEDDINGS_GENERATED => 'Knowledge base indexing',
            self::BOOKINGS_CREATED => 'Bookings created',
            self::BOOKINGS_REALISED => 'Bookings realised',
            self::CONVERSATIONS_HANDLED => 'Conversations handled',
            self::TRANSACTION_ROWS_IMPORTED => 'Transaction rows imported',
            self::PROPERTIES => 'Properties',
            self::USERS => 'Users',
            self::GUESTS => 'Guests',
            self::STAYS => 'Stays',
        };
    }

    /**
     * A seat is "how many exist right now", read back from its own table. It
     * is never incremented: incrementing produces a property count that only
     * ever rises, surviving every hotel anyone deletes.
     */
    public function isSeat(): bool
    {
        return in_array($this, self::seats(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function seats(): array
    {
        return [self::PROPERTIES, self::USERS, self::GUESTS, self::STAYS];
    }

    /**
     * Features that have no source to record from yet, and so are declared
     * but never written. Named here rather than omitted so the reporting
     * endpoint can return an explicit gap instead of a silent zero — a
     * missing meter and a meter reading zero are different facts.
     *
     *  - RECOMMENDATIONS_DELIVERED is inferred today, not measured:
     *    /api/analytics/conversion treats a recommendation as delivered
     *    unless an outcome explicitly records not_delivered, and says so in
     *    its notes. That assumption is fine in a report that labels it; it is
     *    not fine in a usage table, where an estimated number becomes a
     *    disputed invoice later. Needs the measured delivery timestamp from
     *    P1-002 A-1.
     *  - CONVERSATIONS_HANDLED needs a definition of when a conversation
     *    ends. agent_conversations has no status column and the
     *    ConversationStatus enum is unused, so there is no such moment yet.
     *
     * @return array<int, self>
     */
    public static function awaitingSource(): array
    {
        return [self::RECOMMENDATIONS_DELIVERED, self::CONVERSATIONS_HANDLED];
    }

    public function isAwaitingSource(): bool
    {
        return in_array($this, self::awaitingSource(), true);
    }
}
