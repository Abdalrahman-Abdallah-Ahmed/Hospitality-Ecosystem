<?php

namespace App\Services\Ai;

use App\Ai\Agents\AdminAdvisorAgent;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PendingConfirmationChangedException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;

/**
 * One turn of the admin advisor, for the chat endpoint and the WhatsApp staff
 * path alike, with the confirmation rule for hard-to-reverse actions
 * (FR-017, research R1).
 *
 * The tools that need confirmation pause the turn before they run (a
 * framework tool approval). Only the admin's very next message can confirm
 * them, within 10 minutes; a "yes" after that, a "no", or any other message
 * leaves them undone. A confirmation runs the paused calls exactly as
 * listed, and can be used once. At most 10 actions wait for one confirmation:
 * a larger pause is refused so the advisor asks again in batches.
 *
 * Callers wrap the turn in their own tenant, audit and cost context.
 */
class AdvisorTurn
{
    public const CONFIRMATION_WINDOW_SECONDS = 600;

    public const MAX_BATCH = 10;

    /** The title that marks an admin's WhatsApp conversation. */
    public const WHATSAPP_CONVERSATION_TITLE = 'WhatsApp';

    /** How long one turn may hold its conversation: a model turn with tools. */
    private const LOCK_SECONDS = 180;

    /** How long a second message waits for the first before giving up. */
    private const LOCK_WAIT_SECONDS = 30;

    private const CONFIRM_WORDS = ['yes', 'y', 'yep', 'confirm', 'confirmed', 'ok', 'okay', 'go ahead', 'do it', 'proceed',
        'نعم', 'ايوه', 'أيوه', 'اي', 'أي', 'أكد', 'اكد', 'تأكيد', 'تاكيد', 'موافق'];

    private const DECLINE_WORDS = ['no', 'n', 'nope', 'cancel', 'stop', 'dont', 'don t', 'do not',
        'لا', 'إلغاء', 'الغاء', 'لا تفعل'];

    public function __construct(private readonly ConversationStore $conversations) {}

    /**
     * Exception to the four-argument limit: the arguments are the chat
     * request's fields, passed by name from its two callers. Revisit if a
     * third caller appears or a field is added: pass a request object.
     *
     * @param  array<int, mixed>  $attachments
     * @param  list<string>|null  $pendingIds  what the client showed as waiting, to make sure it is still what waits
     *
     * @throws DomainRuleException when a decision is sent with nothing waiting, or the conversation is busy
     * @throws PendingConfirmationChangedException when what waits is not what the client confirmed
     */
    public function handle(User $admin, ?string $conversationId, ?string $message, ?string $decision = null, ?array $pendingIds = null, array $attachments = []): AdvisorTurnResult
    {
        if ($conversationId === null) {
            return $this->turn($admin, null, $message, $decision, $pendingIds, $attachments);
        }

        // One turn per conversation at a time. Two confirmations sent
        // together (a double click, a client retry) would otherwise both read
        // the same waiting actions and both run them: the second now waits,
        // then finds nothing waiting.
        try {
            return Cache::lock('advisor-turn:'.$conversationId, self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                fn () => $this->turn($admin, $conversationId, $message, $decision, $pendingIds, $attachments),
            );
        } catch (LockTimeoutException) {
            throw new DomainRuleException('The previous message in this conversation is still being answered. Try again in a moment.', 409);
        }
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  list<string>|null  $pendingIds
     */
    private function turn(User $admin, ?string $conversationId, ?string $message, ?string $decision, ?array $pendingIds, array $attachments): AdvisorTurnResult
    {
        $message = trim((string) $message);
        $pause = $conversationId ? $this->pendingPause($conversationId) : null;

        if ($decision !== null && $pause === null) {
            throw new DomainRuleException('There is nothing waiting for confirmation.', 422);
        }

        if ($pause !== null && $pendingIds !== null && $this->sameIds($pendingIds, array_keys($pause['pending'])) === false) {
            throw new PendingConfirmationChangedException($this->pendingBlockFromPause($pause));
        }

        $locale = $message !== '' ? self::localeOf($message) : self::localeOf(implode(' ', $pause['pending'] ?? []));
        $conversationId ??= $this->startConversation($admin, $message);

        $agent = AdminAdvisorAgent::make(user: $admin, locale: $locale)->continue($conversationId, $admin);

        $answer = $pause !== null ? ($decision ?? self::classify($message)) : null;
        $response = $this->limitBatch($agent, $this->respond($agent, $pause, $answer, $message, $attachments));

        if ($response === null) {
            return new AdvisorTurnResult($conversationId, $locale === 'ar'
                ? 'طلبت عددًا كبيرًا من الإجراءات التي لا يمكن التراجع عنها دفعة واحدة. اذكر 10 إجراءات كحد أقصى في كل مرة.'
                : 'That is more hard-to-reverse actions than can be confirmed at once. Ask for at most 10 at a time.');
        }

        if ($response->hasPendingApprovals()) {
            $pending = $this->pendingBlock($response->pendingApprovals->all(), CarbonImmutable::now(), $locale);

            return new AdvisorTurnResult($conversationId, $this->confirmationPrompt($pending, $locale), $pending);
        }

        return new AdvisorTurnResult($conversationId, (string) $response->text);
    }

    /**
     * Prompt the advisor with the admin's answer to the waiting actions, or
     * with their message when it is not an answer (which drops them).
     *
     * @param  array{created_at: CarbonImmutable}|null  $pause
     * @param  array<int, mixed>  $attachments
     */
    private function respond(AdminAdvisorAgent $agent, ?array $pause, ?string $answer, string $message, array $attachments): AgentResponse
    {
        return match (true) {
            $answer === 'confirm' && $this->withinWindow($pause) => $agent->prompt(Decision::approveAll()),
            $answer === 'confirm' => $agent->prompt(Decision::rejectAll(
                'The admin confirmed after the 10-minute window, so nothing was done. Tell the admin the confirmation expired and ask again if it is still wanted.'
            )),
            $answer === 'decline' => $agent->prompt(Decision::rejectAll('The admin declined; nothing was done. Acknowledge it briefly.')),
            default => $agent->prompt($message, attachments: $attachments),
        };
    }

    /**
     * Whether the message is, in its entirety, a confirmation or a refusal.
     * Anything longer ("yes but change the date") is neither: it is a new
     * request, and the waiting actions are left undone.
     */
    public static function classify(string $message): ?string
    {
        $normalised = mb_strtolower(trim(preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $message)));

        return match (true) {
            in_array($normalised, self::CONFIRM_WORDS, true) => 'confirm',
            in_array($normalised, self::DECLINE_WORDS, true) => 'decline',
            default => null,
        };
    }

    public static function localeOf(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) ? 'ar' : 'en';
    }

    /**
     * The actions waiting in this conversation: only when the latest message
     * is the paused advisor turn. Once the admin has said anything else, the
     * pause is over and its calls will never run.
     *
     * @return array{created_at: CarbonImmutable, pending: array<string, ?string>, tools: array<string, string>}|null
     */
    public function pendingPause(string $conversationId): ?array
    {
        $latest = DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first();

        if (! $latest || $latest->role !== 'assistant' || $latest->approval_state === null) {
            return null;
        }

        $pending = (array) (json_decode($latest->approval_state, true)['pending'] ?? []);

        if ($pending === []) {
            return null;
        }

        $tools = collect(json_decode((string) $latest->tool_calls, true))
            ->mapWithKeys(fn (array $call) => [$call['id'] => $call['name']])
            ->all();

        return [
            'created_at' => CarbonImmutable::parse($latest->created_at),
            'pending' => $pending,
            'tools' => $tools,
        ];
    }

    /**
     * @param  array{created_at: CarbonImmutable}  $pause
     */
    private function withinWindow(array $pause): bool
    {
        return $pause['created_at']->diffInSeconds(CarbonImmutable::now()) <= self::CONFIRMATION_WINDOW_SECONDS;
    }

    /**
     * A pause of more than MAX_BATCH actions is refused, so the advisor asks
     * again in batches. Twice at most; a third oversized pause is refused
     * without another model call and the turn ends with a fixed message.
     */
    private function limitBatch(AdminAdvisorAgent $agent, AgentResponse $response): ?AgentResponse
    {
        for ($attempt = 0; $attempt < 2 && $response->pendingApprovals->count() > self::MAX_BATCH; $attempt++) {
            $response = $agent->prompt(Decision::rejectAll(
                'Too many hard-to-reverse actions at once: nothing was done. Call the tools again for at most '.self::MAX_BATCH.' of them; the admin confirms each batch.'
            ));
        }

        if ($response->pendingApprovals->count() > self::MAX_BATCH) {
            $agent->prompt(Decision::rejectAll());

            return null;
        }

        return $response;
    }

    /**
     * @param  array<int, PendingApproval>  $approvals
     * @return array{ids: list<string>, items: list<array{id: string, tool: string, summary: ?string}>, expires_at: string, locale: string}
     */
    private function pendingBlock(array $approvals, CarbonImmutable $pausedAt, string $locale): array
    {
        $items = array_map(fn (PendingApproval $approval) => [
            'id' => $approval->id,
            'tool' => $approval->tool,
            'summary' => $approval->reason,
        ], array_values($approvals));

        return [
            'ids' => array_column($items, 'id'),
            'items' => $items,
            'expires_at' => $pausedAt->addSeconds(self::CONFIRMATION_WINDOW_SECONDS)->toIso8601String(),
            'locale' => $locale,
        ];
    }

    /**
     * @param  array{created_at: CarbonImmutable, pending: array<string, ?string>, tools: array<string, string>}  $pause
     */
    private function pendingBlockFromPause(array $pause): array
    {
        $approvals = [];

        foreach ($pause['pending'] as $id => $summary) {
            $approvals[] = new PendingApproval($id, $pause['tools'][$id] ?? 'unknown', [], $summary);
        }

        return $this->pendingBlock($approvals, $pause['created_at'], self::localeOf(implode(' ', $pause['pending'])));
    }

    /**
     * The confirmation question, built from the tools' own summaries rather
     * than the model's wording, so the admin confirms what will really run.
     *
     * @param  array{items: list<array{summary: ?string}>}  $pending
     */
    private function confirmationPrompt(array $pending, string $locale): string
    {
        $lines = collect($pending['items'])->values()->map(fn (array $item, int $index) => ($index + 1).'. '.$item['summary'])->implode("\n");

        return $locale === 'ar'
            ? "يرجى التأكيد:\n{$lines}\nأرسل «نعم» للتأكيد أو «لا» للإلغاء. ينتهي هذا الطلب خلال 10 دقائق."
            : "Please confirm:\n{$lines}\nReply YES to confirm or NO to cancel. This expires in 10 minutes.";
    }

    /**
     * The admin's WhatsApp conversation, kept apart from their web chats: a
     * "yes" on WhatsApp must never confirm actions that were put to them in
     * the web chat, where they may not be looking.
     */
    public function whatsAppConversation(User $admin): string
    {
        $existing = Conversation::query()
            ->where('participant_type', Conversation::participantType($admin))
            ->where('participant_id', Conversation::participantKey($admin))
            ->where('title', self::WHATSAPP_CONVERSATION_TITLE)
            ->latest('updated_at')
            ->value('id');

        return $existing ?? $this->conversations->storeConversation(
            Conversation::participantType($admin),
            Conversation::participantKey($admin),
            self::WHATSAPP_CONVERSATION_TITLE,
        );
    }

    /**
     * Created before the turn, so the tools know the conversation id while
     * they run and can record it in the audit trail (R3).
     */
    private function startConversation(User $admin, string $message): string
    {
        return $this->conversations->storeConversation(
            Conversation::participantType($admin),
            Conversation::participantKey($admin),
            Str::limit($message !== '' ? $message : 'Advisor', 50, preserveWords: true),
        );
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameIds(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return array_values($a) === array_values($b);
    }
}
