<?php

namespace App\Support\Audit;

use App\Enums\ActorKind;
use App\Enums\EvidenceLevel;
use App\Models\AiActivity;
use App\Models\AiUsageLog;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\MeterEvent;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Ai\AiRun;
use App\Support\Impersonation\CurrentImpersonation;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

/**
 * The single writer for the audit trail. Called automatically by the
 * RecordsEvents trait on every create/update/delete of an audited model, and
 * directly for the few semantic events that are not a plain CRUD verb
 * (transaction.reversed, the import summaries).
 *
 * Recording can be paused (importers, so a 10k-row upload does not produce
 * 10k rows) and the actor kind can be forced to ai_agent for the duration of
 * a callback (the AI jobs and tools).
 *
 * In an impersonation session's request the actor is the real super admin, with
 * the user in context.impersonated_user_id (spec 027). A job the request
 * dispatched runs later with no token, so it stays with the hotel user (R8).
 */
class EventLogger
{
    private static bool $recording = true;

    private static ?ActorKind $actorKindOverride = null;

    /** The human an AI agent is acting for, when no one is signed in (queue jobs). */
    private static ?Model $onBehalfOf = null;

    /** @var array<string, mixed> Which agent, tool and conversation produced the write. */
    private static array $aiContext = [];

    /**
     * Open write captures (see capture()), innermost last. Each lists the
     * records written while it was open.
     *
     * @var list<list<array{subject: Model, event_type: string, audited: bool}>>
     */
    private static array $captures = [];

    /**
     * Bookkeeping that is not hotel data: writing it is not a change made on
     * the hotel's behalf, so a capture ignores it.
     *
     * @var list<class-string>
     */
    private const NOT_HOTEL_DATA = [
        EventLog::class,
        AiActivity::class,
        AiUsageLog::class,
        MeterEvent::class,
        UsageCounter::class,
        Conversation::class,
        ConversationMessage::class,
    ];

    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * Record one event. Silently does nothing while recording is paused.
     *
     * $context adds identifiers to the entry's context (for example which
     * conversation was opened). Never put message text or other content in
     * it (FR-024).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function record(
        Model $subject,
        string $verb,
        ?array $changes = null,
        ?string $reason = null,
        ?EvidenceLevel $evidence = null,
        array $context = [],
    ): void {
        self::write($subject, Str::snake(class_basename($subject)).'.'.$verb, self::resolveHotelId($subject), [
            'changes' => $changes,
            'context' => $context,
            'evidence_level' => $evidence,
            'reason' => $reason,
        ]);
    }

    /**
     * Record what a super admin did on the platform. The row never has a
     * hotel, whatever the subject or the current hotel, so it cannot appear
     * on any tenant event log. It is written with the current hotel cleared:
     * setting hotel_id to null is not enough, because BelongsToHotel's create
     * hook fills an empty hotel_id from the current hotel.
     *
     * Never put a password, a hash or a token in $changes or $context.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function recordPlatform(
        string $eventType,
        Model $subject,
        ?array $changes = null,
        array $context = [],
    ): void {
        TenantContext::runForHotel(null, fn () => self::write($subject, $eventType, null, [
            'changes' => $changes,
            'context' => $context,
        ]));
    }

    /**
     * A write an impersonation session made to a record that is not audited,
     * by field name only, never values (spec 027 FR-019b). Not counted toward a
     * capture: the model event already was, so what a capture lists doesn't
     * depend on the token.
     *
     * @param  list<string>  $fields
     */
    public static function recordSessionWrite(Model $subject, string $verb, array $fields): void
    {
        self::write($subject, Str::snake(class_basename($subject)).'.'.$verb, self::resolveHotelId($subject), [
            'context' => ['changed_fields' => $fields],
            'notice' => false,
        ]);
    }

    /**
     * The one place an audit row is built. Silently does nothing while
     * recording is paused.
     *
     * @param  array{changes?: array|null, context?: array<string, scalar|list<string>|null>, evidence_level?: EvidenceLevel|null, reason?: string|null, notice?: bool}  $details
     */
    private static function write(Model $subject, string $eventType, ?string $hotelId, array $details): void
    {
        if ($details['notice'] ?? true) {
            self::notice($subject, $eventType, audited: true);
        }

        if (! self::$recording) {
            return;
        }

        $actor = Auth::user() ?? self::$onBehalfOf;
        $session = CurrentImpersonation::token();

        EventLog::create([
            'hotel_id' => $hotelId,
            'event_type' => $eventType,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            // The id from the token, so the row names the super admin even if they
            // were deleted while this request was running.
            'actor_id' => $session?->getAttribute('impersonator_id') ?? $actor?->getKey(),
            'actor_kind' => self::resolveActorKind($actor)->value,
            'changes' => ($details['changes'] ?? null) ?: null,
            'context' => array_merge(self::context() ?? [], $session ? ['impersonated_user_id' => $actor->getKey()] : [], $details['context'] ?? []) ?: null,
            'evidence_level' => ($details['evidence_level'] ?? EvidenceLevel::L1)->value,
            'reason' => $details['reason'] ?? null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Run a callback and list the records it wrote: every audited event, and
     * every model created, updated or deleted (wired to the Eloquent events
     * in AppServiceProvider), plus writes a caller declares with notice().
     * For the AI action recorder, which needs to know whether an AI write
     * actually changed anything (SPEC-083).
     *
     * @return array{0: mixed, 1: list<array{subject: Model, event_type: string, audited: bool}>}
     */
    public static function capture(callable $callback): array
    {
        self::$captures[] = [];

        try {
            $result = $callback();
        } catch (Throwable $e) {
            array_pop(self::$captures);

            throw $e;
        }

        return [$result, array_pop(self::$captures)];
    }

    /**
     * Count a write toward every open capture. Called for audited events and
     * model events; called directly by code that writes through a bulk query
     * (no model events) so the write is not mistaken for no change.
     */
    public static function notice(Model $subject, string $eventType, bool $audited = false): void
    {
        if (self::$captures === [] || in_array($subject::class, self::NOT_HOTEL_DATA, true)) {
            return;
        }

        foreach (array_keys(self::$captures) as $i) {
            self::$captures[$i][] = ['subject' => $subject, 'event_type' => $eventType, 'audited' => $audited];
        }
    }

    /**
     * A model event (created, updated, deleted) seen by the wildcard
     * listener in AppServiceProvider.
     */
    public static function noticeModelEvent(Model $model, string $verb): void
    {
        self::notice($model, Str::snake(class_basename($model)).'.'.$verb);
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
     * (actor_kind = ai_agent), restoring the prior setting afterward. For the
     * AI jobs and the agent tools that write records.
     *
     * $onBehalfOf names the human the agent acts for, recorded as the actor
     * when no one is signed in (a WhatsApp turn runs in a queue job). $ai is
     * stored as `context.ai` — which agent, tool and conversation did it — so
     * an AI write can be traced without reading the conversation.
     *
     * @param  array<string, mixed>  $ai
     */
    public static function asAiAgent(callable $callback, ?Model $onBehalfOf = null, array $ai = []): mixed
    {
        $previous = [self::$actorKindOverride, self::$onBehalfOf, self::$aiContext];
        self::$actorKindOverride = ActorKind::AI_AGENT;
        self::$onBehalfOf = $onBehalfOf ?? self::$onBehalfOf;
        self::$aiContext = $ai !== [] ? $ai : self::$aiContext;

        try {
            return $callback();
        } finally {
            [self::$actorKindOverride, self::$onBehalfOf, self::$aiContext] = $previous;
        }
    }

    /**
     * Run a callback with `$user` as the actor of every event it records when
     * no one is signed in, as a person (actor_kind = user), restoring the
     * prior setting afterward. For the queued reservation import, which
     * records its summary event in a job on behalf of the uploader (FR-032).
     */
    public static function actingAs(User $user, callable $callback): mixed
    {
        $previous = [self::$actorKindOverride, self::$onBehalfOf];
        self::$actorKindOverride = ActorKind::USER;
        self::$onBehalfOf = $user;

        try {
            return $callback();
        } finally {
            [self::$actorKindOverride, self::$onBehalfOf] = $previous;
        }
    }

    /**
     * Who is acting right now, as the audit trail would record it: an AI
     * agent inside asAiAgent(), otherwise the signed-in user, otherwise the
     * system. For writers that must label their own records the same way.
     */
    public static function currentActorKind(): ActorKind
    {
        return self::resolveActorKind(Auth::user());
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

        if (self::$actorKindOverride === ActorKind::AI_AGENT) {
            $ai = array_filter([...self::$aiContext, 'run_id' => AiRun::id()], fn ($value) => $value !== null);

            if ($ai !== []) {
                $context['ai'] = $ai;
            }
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
