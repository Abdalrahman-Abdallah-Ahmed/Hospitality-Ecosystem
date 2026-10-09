<?php

namespace App\Services\Ai;

/**
 * One advisor turn's outcome: the conversation it belongs to, the text to
 * show or send, and the hard-to-reverse actions waiting for the admin's
 * confirmation, if any (contracts/advisor-chat-api.md).
 */
final class AdvisorTurnResult
{
    /**
     * @param  array{ids: list<string>, items: list<array{id: string, tool: string, summary: ?string}>, expires_at: string, locale: string}|null  $pendingConfirmation
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $reply,
        public readonly ?array $pendingConfirmation = null,
    ) {}
}
