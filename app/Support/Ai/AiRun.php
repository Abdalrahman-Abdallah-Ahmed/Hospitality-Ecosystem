<?php

namespace App\Support\Ai;

use App\Enums\AiActivityResult;
use App\Enums\AiAgentName;
use App\Enums\AiTriggerKind;
use App\Models\AiActivity;
use App\Models\AiUsageLog;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The AI run happening right now: one agent turn or one AI job (SPEC-083).
 *
 * Opened and closed only by the outermost AiCostContext::for(), the one
 * point every AI call already passes through, so a new agent is tracked
 * without being told to. Its id is stamped on the provider calls it makes
 * (ai_usage_logs), on its audit entries (context.ai.run_id) and on every AI
 * activity entry written inside it. On close it writes one ai_run entry
 * whose usage is summed from those provider calls.
 *
 * Process-static, like TenantContext and EventLogger; reset between tests.
 */
class AiRun
{
    /**
     * @var array{id: string, agent: AiAgentName, kind: AiTriggerKind, hotel_id: ?string, acted_for: ?Model, conversation_id: ?string}|null
     */
    private static ?array $current = null;

    public static function open(AiAgentName $agent, AiTriggerKind $kind, ?Hotel $hotel, ?Model $actedFor, ?string $conversationId): string
    {
        self::$current = [
            'id' => (string) Str::uuid7(),
            'agent' => $agent,
            'kind' => $kind,
            'hotel_id' => $hotel?->getKey(),
            'acted_for' => $actedFor,
            'conversation_id' => $conversationId,
        ];

        return self::$current['id'];
    }

    public static function id(): ?string
    {
        return self::$current['id'] ?? null;
    }

    public static function hotelId(): ?string
    {
        return self::$current['hotel_id'] ?? null;
    }

    public static function conversationId(): ?string
    {
        return self::$current['conversation_id'] ?? null;
    }

    /**
     * The conversation, once known. The advisor and a guest's first message
     * create theirs inside the run, so it can only be named late.
     */
    public static function setConversation(?string $conversationId): void
    {
        if (self::$current !== null && $conversationId !== null) {
            self::$current['conversation_id'] = $conversationId;
        }
    }

    /**
     * Write the run's entry and end it. Entries written before the
     * conversation was known get it now: the one update ai_activities
     * allows, done as a bulk query so it never passes the model's guard.
     */
    public static function close(AiActivityResult $result, ?string $reason = null): void
    {
        $run = self::$current;
        self::$current = null;

        if ($run === null) {
            return;
        }

        $usage = AiUsageLog::query()
            ->where('ai_run_id', $run['id'])
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens, COALESCE(SUM(cached_tokens), 0) AS cached_tokens')
            ->toBase()
            ->first();

        AiActivityRecorder::run(
            id: $run['id'],
            agent: $run['agent'],
            kind: $run['kind'],
            hotelId: $run['hotel_id'],
            actedFor: $run['acted_for'],
            conversationId: $run['conversation_id'],
            result: $result,
            reason: $reason,
            usage: [
                'requests' => (int) $usage->requests,
                'input_tokens' => (int) $usage->input_tokens,
                'output_tokens' => (int) $usage->output_tokens,
                'cached_tokens' => (int) $usage->cached_tokens,
            ],
        );

        if ($run['conversation_id'] !== null) {
            AiActivity::withoutGlobalScope('hotel')
                ->where('ai_run_id', $run['id'])
                ->whereNull('conversation_id')
                ->toBase()
                ->update(['conversation_id' => $run['conversation_id']]);
        }
    }

    /**
     * A run stopped before it started (the spend ceiling): one refused
     * entry, with no usage, since nothing was called.
     */
    public static function refused(AiAgentName $agent, AiTriggerKind $kind, ?Hotel $hotel, ?Model $actedFor, ?string $conversationId, string $reason): void
    {
        AiActivityRecorder::run(
            id: (string) Str::uuid7(),
            agent: $agent,
            kind: $kind,
            hotelId: $hotel?->getKey(),
            actedFor: $actedFor,
            conversationId: $conversationId,
            result: AiActivityResult::REFUSED,
            reason: $reason,
            usage: null,
        );
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
