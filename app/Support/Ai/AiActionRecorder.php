<?php

namespace App\Support\Ai;

use App\Enums\AiActivityResult;
use App\Enums\AiAgentName;
use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Stringable;
use Throwable;

/**
 * Every AI write action goes through here, so each one leaves exactly one
 * data_change entry, whatever happened (SPEC-083 R3):
 *
 * - succeeded: something was written and committed. The target is the first
 *   audited record it changed (or the first record, if none is audited).
 * - refused: the tool returned without writing anything — a permission,
 *   validation or business rule said no. Tools say no by returning a
 *   message, so that message (system-written, trimmed) is the reason.
 * - failed: an exception escaped. The write has been rolled back by then,
 *   and the entry is written after the rollback, so it survives.
 *
 * Used by GuardedTool (Admin AI), AuditedTool (every other agent) and the AI
 * jobs (forJob()).
 */
class AiActionRecorder
{
    /**
     * Run an AI tool's write in a transaction, as the AI acting for
     * $actedFor, and record how it ended.
     */
    public static function around(
        AiAgentName|string $agent,
        string $tool,
        ?Model $actedFor,
        ?string $toolCallId,
        ?string $conversationId,
        callable $write,
    ): mixed {
        return self::record($agent, $tool, $actedFor, $toolCallId, $conversationId, fn () => DB::transaction($write), recordRefusal: true);
    }

    /**
     * Record an AI job's write step. The job keeps its own transactions (some
     * call a model inside one, which must not hold a lock), and a job that
     * wrote nothing leaves no entry: its ai_run entry already says it ran.
     */
    public static function forJob(AiAgentName|string $agent, string $job, ?Model $actedFor, callable $write): mixed
    {
        return self::record($agent, $job, $actedFor, null, null, $write, recordRefusal: false);
    }

    /**
     * A refusal that happened before the write could run (the Admin AI
     * guard's permission check).
     */
    public static function refused(AiAgentName|string $agent, string $tool, ?Model $actedFor, ?string $conversationId, string $reason): void
    {
        AiActivityRecorder::dataChange(
            agent: $agent,
            tool: $tool,
            result: AiActivityResult::REFUSED,
            action: 'refused',
            actedFor: $actedFor,
            conversationId: $conversationId,
            reason: $reason,
        );
    }

    private static function record(
        AiAgentName|string $agent,
        string $tool,
        ?Model $actedFor,
        ?string $toolCallId,
        ?string $conversationId,
        callable $write,
        bool $recordRefusal,
    ): mixed {
        $agentName = $agent instanceof AiAgentName ? $agent->value : $agent;
        $conversationId ??= AiRun::conversationId();

        try {
            [$result, $writes] = EventLogger::asAiAgent(
                fn () => EventLogger::capture($write),
                onBehalfOf: $actedFor,
                ai: array_filter([
                    'agent' => $agentName,
                    'tool' => AiActivityRecorder::toolName($tool),
                    'tool_call_id' => $toolCallId,
                    'conversation_id' => $conversationId,
                ], fn ($value) => $value !== null),
            );
        } catch (Throwable $e) {
            AiActivityRecorder::dataChange(
                agent: $agent,
                tool: $tool,
                result: AiActivityResult::FAILED,
                action: 'failed',
                actedFor: $actedFor,
                conversationId: $conversationId,
                reason: class_basename($e),
            );

            throw $e;
        }

        if ($writes === []) {
            if ($recordRefusal) {
                AiActivityRecorder::dataChange(
                    agent: $agent,
                    tool: $tool,
                    result: AiActivityResult::REFUSED,
                    action: 'refused',
                    actedFor: $actedFor,
                    conversationId: $conversationId,
                    reason: self::reasonFrom($result),
                );
            }

            return $result;
        }

        $first = collect($writes)->firstWhere('audited', true) ?? $writes[0];

        AiActivityRecorder::dataChange(
            agent: $agent,
            tool: $tool,
            result: AiActivityResult::SUCCEEDED,
            action: $first['event_type'],
            target: $first['subject'],
            changesCount: collect($writes)->map(fn (array $write) => $write['subject']::class.':'.$write['subject']->getKey())->unique()->count(),
            actedFor: $actedFor,
            conversationId: $conversationId,
        );

        return $result;
    }

    private static function reasonFrom(mixed $result): ?string
    {
        return is_string($result) || $result instanceof Stringable ? (string) $result : null;
    }
}
