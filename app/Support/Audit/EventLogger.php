<?php

namespace App\Support\Audit;

use App\Enums\ActorKind;
use App\Enums\EvidenceLevel;
use App\Models\EventLog;
use App\Models\Hotel;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The single writer for the audit trail. Called automatically by the
 * RecordsEvents trait on every create/update/delete of an audited model, and
 * directly for the few semantic events that are not a plain CRUD verb
 * (transaction.reversed, the import summaries).
 *
 * Recording can be paused (importers, so a 10k-row upload does not produce
 * 10k rows) and the actor kind can be forced to ai_agent for the duration of
 * a callback (the AI jobs and tools).
 */
class EventLogger
{
    private static bool $recording = true;

    private static ?ActorKind $actorKindOverride = null;

    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * Record one event. Silently does nothing while recording is paused.
     */
    public static function record(
        Model $subject,
        string $verb,
        ?array $changes = null,
        ?string $reason = null,
        ?EvidenceLevel $evidence = null,
    ): void {
        if (! self::$recording) {
            return;
        }

        $actor = Auth::user();

        EventLog::create([
            'hotel_id' => self::resolveHotelId($subject),
            'event_type' => Str::snake(class_basename($subject)).'.'.$verb,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_kind' => self::resolveActorKind($actor)->value,
            'changes' => $changes ?: null,
            'context' => self::context(),
            'evidence_level' => ($evidence ?? EvidenceLevel::L1)->value,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Run a callback with event recording paused, restoring it afterward even
     * if the callback throws. For bulk importers, which record one summary
     * event of their own instead.
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $previous = self::$recording;
        self::$recording = false;

        try {
            return $callback();
        } finally {
            self::$recording = $previous;
        }
    }

    /**
     * Run a callback with every event it produces attributed to an AI agent
     * (actor_kind = ai_agent, no human actor), restoring the prior setting
     * afterward. For the AI jobs and the agent tools that write records.
     */
    public static function asAiAgent(callable $callback): mixed
    {
        $previous = self::$actorKindOverride;
        self::$actorKindOverride = ActorKind::AI_AGENT;

        try {
            return $callback();
        } finally {
            self::$actorKindOverride = $previous;
        }
    }

    private static function resolveActorKind(?Model $actor): ActorKind
    {
        if (self::$actorKindOverride !== null) {
            return self::$actorKindOverride;
        }

        return $actor ? ActorKind::USER : ActorKind::SYSTEM;
    }

    private static function resolveHotelId(Model $subject): ?string
    {
        if ($subject instanceof Hotel) {
            return $subject->getKey();
        }

        return $subject->getAttribute('hotel_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function context(): ?array
    {
        $context = [];

        if (! app()->runningInConsole() && ($request = request()) !== null) {
            $context['ip'] = $request->ip();
            $context['route'] = $request->path();
        }

        if (app()->runningInConsole()) {
            $context['queue'] = true;
        }

        return $context ?: null;
    }

    /**
     * Normalise a value for JSON storage in `changes`: enums become their
     * backing value, dates become ISO 8601 strings, everything else is left
     * as-is.
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }
}
